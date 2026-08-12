<?php

namespace App\Actions\Admin;

use App\Exceptions\FileDeleteFailedException;
use App\Models\File;
use App\Models\User;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeleteFile
{
    private const CLAIM_TTL_MINUTES = 5;

    public function __construct(
        private FilesystemFactory $filesystems,
        private SecurityActivityRecorder $activityRecorder,
    ) {}

    public function handle(File $file, User $actor): void
    {
        try {
            $filesystem = $this->filesystems->disk($file->disk);
        } catch (Throwable $exception) {
            $this->logFailure($file, $exception::class, 'filesystem_resolution');
            throw new FileDeleteFailedException;
        }

        $deletionToken = (string) Str::uuid();
        [$lockedFile, $activeDeletionToken] = $this->claimDeletion($file, $deletionToken);

        try {
            $fileExists = $filesystem->exists($lockedFile->path);
        } catch (Throwable $exception) {
            $this->restoreVisibleMetadata($lockedFile, $deletionToken);
            $this->logFailure($lockedFile, $exception::class, 'filesystem_inspection');
            throw new FileDeleteFailedException;
        }

        if ($activeDeletionToken !== null) {
            if ($fileExists) {
                $this->logFailure($lockedFile, 'deletion_already_in_progress', 'claim');
                throw new FileDeleteFailedException;
            }

            $lockedFile = $this->claimMissingFileDeletion(
                $lockedFile,
                $activeDeletionToken,
                $deletionToken,
            );
        }

        if ($fileExists && ! $this->deletePhysicalFile($filesystem, $lockedFile, $deletionToken)) {
            throw new FileDeleteFailedException;
        }

        try {
            $finalized = $this->finalizeDeletion($lockedFile, $actor, $deletionToken);
        } catch (Throwable $exception) {
            $this->logFailure($lockedFile, $exception::class, 'metadata_delete');
            throw $exception;
        }

        if (! $finalized) {
            $this->logFailure($lockedFile, 'deletion_ownership_changed', 'metadata_delete');
            throw new FileDeleteFailedException;
        }
    }

    /**
     * @return array{File, ?string}
     */
    private function claimDeletion(File $file, string $deletionToken): array
    {
        $claim = DB::transaction(function () use ($deletionToken, $file): ?array {
            $lockedFile = File::query()->lockForUpdate()->findOrFail($file->getKey());

            $claimIsActive = $lockedFile->deletion_token !== null
                && $lockedFile->deletion_started_at?->isAfter(now()->subMinutes(self::CLAIM_TTL_MINUTES));

            if ($this->pendingUploadLeaseIsActive($lockedFile)) {
                return null;
            }

            if ($claimIsActive) {
                return [$lockedFile, $lockedFile->deletion_token];
            }

            $lockedFile->forceFill([
                'deletion_token' => $deletionToken,
                'deletion_started_at' => now(),
            ])->save();

            return [$lockedFile, null];
        }, attempts: 3);

        if ($claim === null) {
            $this->logFailure($file, 'deletion_already_in_progress', 'claim');
            throw new FileDeleteFailedException;
        }

        return $claim;
    }

    private function claimMissingFileDeletion(
        File $file,
        string $activeDeletionToken,
        string $deletionToken,
    ): File {
        $lockedFile = DB::transaction(function () use ($activeDeletionToken, $deletionToken, $file): ?File {
            $lockedFile = File::query()->lockForUpdate()->find($file->getKey());

            if (
                $lockedFile === null
                || $this->pendingUploadLeaseIsActive($lockedFile)
                || $lockedFile->deletion_token !== $activeDeletionToken
            ) {
                return null;
            }

            $lockedFile->forceFill([
                'deletion_token' => $deletionToken,
                'deletion_started_at' => now(),
            ])->save();

            return $lockedFile;
        }, attempts: 3);

        if ($lockedFile === null) {
            $this->logFailure($file, 'deletion_ownership_changed', 'claim');
            throw new FileDeleteFailedException;
        }

        return $lockedFile;
    }

    private function pendingUploadLeaseIsActive(File $file): bool
    {
        return $file->status === File::STATUS_PENDING
            && $file->created_at?->isAfter(now()->subMinutes(File::PENDING_UPLOAD_LEASE_MINUTES));
    }

    private function deletePhysicalFile(Filesystem $filesystem, File $file, string $deletionToken): bool
    {
        try {
            $deleted = $filesystem->delete($file->path);

            if (! $deleted) {
                $deleted = ! $filesystem->exists($file->path);
            }
        } catch (Throwable $exception) {
            try {
                $deleted = ! $filesystem->exists($file->path);
            } catch (Throwable) {
                $deleted = false;
            }

            if (! $deleted) {
                $this->restoreVisibleMetadata($file, $deletionToken);
                $this->logFailure($file, $exception::class, 'physical_delete');

                return false;
            }
        }

        if (! $deleted) {
            $this->restoreVisibleMetadata($file, $deletionToken);
            $this->logFailure($file, 'delete_returned_false', 'physical_delete');
        }

        return $deleted;
    }

    private function finalizeDeletion(File $file, User $actor, string $deletionToken): bool
    {
        return DB::transaction(function () use ($actor, $deletionToken, $file): bool {
            $lockedFile = File::query()->lockForUpdate()->find($file->getKey());

            if ($lockedFile === null) {
                return true;
            }

            if ($lockedFile->deletion_token !== $deletionToken) {
                return false;
            }

            $this->activityRecorder->record($lockedFile, $actor, 'admin', 'file_deleted');
            $lockedFile->delete();

            return true;
        }, attempts: 3);
    }

    private function restoreVisibleMetadata(File $file, string $deletionToken): void
    {
        try {
            DB::transaction(function () use ($deletionToken, $file): void {
                $lockedFile = File::query()->lockForUpdate()->find($file->getKey());

                if ($lockedFile?->deletion_token === $deletionToken) {
                    $lockedFile->forceFill([
                        'deletion_token' => null,
                        'deletion_started_at' => null,
                    ])->save();
                }
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function logFailure(File $file, string $failure, string $stage): void
    {
        Log::warning('File deletion did not complete; metadata was retained.', [
            'file_id' => $file->getKey(),
            'disk' => $file->disk,
            'stage' => $stage,
            'failure' => $failure,
            'request_id' => Context::get('request_id'),
        ]);
    }
}
