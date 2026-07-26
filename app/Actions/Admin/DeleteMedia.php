<?php

namespace App\Actions\Admin;

use App\Exceptions\MediaDeleteFailedException;
use App\Models\Media;
use App\Models\User;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeleteMedia
{
    private const CLAIM_TTL_MINUTES = 5;

    public function __construct(
        private FilesystemFactory $filesystems,
        private SecurityActivityRecorder $activityRecorder,
    ) {}

    public function handle(Media $media, User $actor): void
    {
        try {
            $filesystem = $this->filesystems->disk($media->disk);
        } catch (Throwable $exception) {
            $this->logFailure($media, $exception::class, 'filesystem_resolution');
            throw new MediaDeleteFailedException;
        }

        $deletionToken = (string) Str::uuid();
        [$lockedMedia, $activeDeletionToken] = $this->claimDeletion($media, $deletionToken);

        try {
            $fileExists = $filesystem->exists($lockedMedia->path);
        } catch (Throwable $exception) {
            $this->restoreVisibleMetadata($lockedMedia, $deletionToken);
            $this->logFailure($lockedMedia, $exception::class, 'filesystem_inspection');
            throw new MediaDeleteFailedException;
        }

        if ($activeDeletionToken !== null) {
            if ($fileExists) {
                $this->logFailure($lockedMedia, 'deletion_already_in_progress', 'claim');
                throw new MediaDeleteFailedException;
            }

            $lockedMedia = $this->claimMissingFileDeletion(
                $lockedMedia,
                $activeDeletionToken,
                $deletionToken,
            );
        }

        if ($fileExists && ! $this->deletePhysicalFile($filesystem, $lockedMedia, $deletionToken)) {
            throw new MediaDeleteFailedException;
        }

        try {
            $finalized = $this->finalizeDeletion($lockedMedia, $actor, $deletionToken);
        } catch (Throwable $exception) {
            $this->logFailure($lockedMedia, $exception::class, 'metadata_delete');
            throw $exception;
        }

        if (! $finalized) {
            $this->logFailure($lockedMedia, 'deletion_ownership_changed', 'metadata_delete');
            throw new MediaDeleteFailedException;
        }
    }

    /**
     * @return array{Media, ?string}
     */
    private function claimDeletion(Media $media, string $deletionToken): array
    {
        $claim = DB::transaction(function () use ($deletionToken, $media): ?array {
            $lockedMedia = Media::query()->lockForUpdate()->findOrFail($media->getKey());

            $claimIsActive = $lockedMedia->deletion_token !== null
                && $lockedMedia->deletion_started_at?->isAfter(now()->subMinutes(self::CLAIM_TTL_MINUTES));

            if ($this->pendingUploadLeaseIsActive($lockedMedia)) {
                return null;
            }

            if ($claimIsActive) {
                return [$lockedMedia, $lockedMedia->deletion_token];
            }

            $lockedMedia->forceFill([
                'deletion_token' => $deletionToken,
                'deletion_started_at' => now(),
            ])->save();

            return [$lockedMedia, null];
        }, attempts: 3);

        if ($claim === null) {
            $this->logFailure($media, 'deletion_already_in_progress', 'claim');
            throw new MediaDeleteFailedException;
        }

        return $claim;
    }

    private function claimMissingFileDeletion(
        Media $media,
        string $activeDeletionToken,
        string $deletionToken,
    ): Media {
        $lockedMedia = DB::transaction(function () use ($activeDeletionToken, $deletionToken, $media): ?Media {
            $lockedMedia = Media::query()->lockForUpdate()->find($media->getKey());

            if (
                $lockedMedia === null
                || $this->pendingUploadLeaseIsActive($lockedMedia)
                || $lockedMedia->deletion_token !== $activeDeletionToken
            ) {
                return null;
            }

            $lockedMedia->forceFill([
                'deletion_token' => $deletionToken,
                'deletion_started_at' => now(),
            ])->save();

            return $lockedMedia;
        }, attempts: 3);

        if ($lockedMedia === null) {
            $this->logFailure($media, 'deletion_ownership_changed', 'claim');
            throw new MediaDeleteFailedException;
        }

        return $lockedMedia;
    }

    private function pendingUploadLeaseIsActive(Media $media): bool
    {
        return $media->status === Media::STATUS_PENDING
            && $media->created_at?->isAfter(now()->subMinutes(Media::PENDING_UPLOAD_LEASE_MINUTES));
    }

    private function deletePhysicalFile(Filesystem $filesystem, Media $media, string $deletionToken): bool
    {
        try {
            $deleted = $filesystem->delete($media->path);

            if (! $deleted) {
                $deleted = ! $filesystem->exists($media->path);
            }
        } catch (Throwable $exception) {
            try {
                $deleted = ! $filesystem->exists($media->path);
            } catch (Throwable) {
                $deleted = false;
            }

            if (! $deleted) {
                $this->restoreVisibleMetadata($media, $deletionToken);
                $this->logFailure($media, $exception::class, 'physical_delete');

                return false;
            }
        }

        if (! $deleted) {
            $this->restoreVisibleMetadata($media, $deletionToken);
            $this->logFailure($media, 'delete_returned_false', 'physical_delete');
        }

        return $deleted;
    }

    private function finalizeDeletion(Media $media, User $actor, string $deletionToken): bool
    {
        return DB::transaction(function () use ($actor, $deletionToken, $media): bool {
            $lockedMedia = Media::query()->lockForUpdate()->find($media->getKey());

            if ($lockedMedia === null) {
                return true;
            }

            if ($lockedMedia->deletion_token !== $deletionToken) {
                return false;
            }

            $this->activityRecorder->record($lockedMedia, $actor, 'admin', 'media_deleted');
            $lockedMedia->delete();

            return true;
        }, attempts: 3);
    }

    private function restoreVisibleMetadata(Media $media, string $deletionToken): void
    {
        try {
            DB::transaction(function () use ($deletionToken, $media): void {
                $lockedMedia = Media::query()->lockForUpdate()->find($media->getKey());

                if ($lockedMedia?->deletion_token === $deletionToken) {
                    $lockedMedia->forceFill([
                        'deletion_token' => null,
                        'deletion_started_at' => null,
                    ])->save();
                }
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function logFailure(Media $media, string $failure, string $stage): void
    {
        Log::warning('Media deletion did not complete; metadata was retained.', [
            'media_id' => $media->getKey(),
            'disk' => $media->disk,
            'stage' => $stage,
            'failure' => $failure,
            'request_id' => Context::get('request_id'),
        ]);
    }
}
