<?php

namespace App\Actions\Admin;

use App\Models\Media;
use App\Models\User;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StoreMedia
{
    public function __construct(
        private FilesystemFactory $filesystems,
        private SecurityActivityRecorder $activityRecorder,
    ) {}

    public function handle(UploadedFile $file, User $actor): Media
    {
        $mimeType = (string) $file->getMimeType();
        $extension = $this->extensionForMimeType($mimeType);
        [$width, $height] = $this->imageDimensions($file);
        $directory = 'media/'.now()->format('Y/m');
        $filename = Str::uuid().'.'.$extension;
        $path = $directory.'/'.$filename;
        $disk = (string) config('filesystems.media', 'public');
        $filesystem = $this->filesystems->disk($disk);
        $media = $this->createPendingMedia($file, $actor, $disk, $path, $mimeType, $extension, $width, $height);
        $fileWasStored = false;

        try {
            $storedMedia = DB::transaction(function () use ($actor, $file, $filesystem, &$fileWasStored, $media, $path): ?Media {
                $lockedMedia = Media::query()->lockForUpdate()->findOrFail($media->getKey());

                if ($lockedMedia->status !== Media::STATUS_PENDING || $lockedMedia->deletion_token !== null) {
                    return null;
                }

                $fileWasStored = $filesystem->put($path, $file->getContent(), ['visibility' => 'public']);

                if (! $fileWasStored) {
                    throw new RuntimeException('Media file could not be stored.');
                }

                $lockedMedia->forceFill(['status' => Media::STATUS_READY])->save();
                $this->activityRecorder->record($lockedMedia, $actor, 'admin', 'media_uploaded');

                return $lockedMedia;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($fileWasStored) {
                $this->retainFailedMedia($media, $exception, 'metadata_finalization');
            } else {
                $this->compensateFailedStorage($filesystem, $media, $exception);
            }

            throw $exception;
        }

        if ($storedMedia === null) {
            throw new RuntimeException('Media upload lease expired before the file was stored.');
        }

        return $storedMedia;
    }

    private function createPendingMedia(
        UploadedFile $file,
        User $actor,
        string $disk,
        string $path,
        string $mimeType,
        string $extension,
        int $width,
        int $height,
    ): Media {
        return DB::transaction(function () use ($actor, $disk, $extension, $file, $height, $mimeType, $path, $width): Media {
            $media = new Media;
            $media->forceFill([
                'name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size' => (int) $file->getSize(),
                'width' => $width,
                'height' => $height,
                'status' => Media::STATUS_PENDING,
                'created_by' => $actor->getKey(),
            ])->save();

            return $media;
        }, attempts: 3);
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new RuntimeException('Unsupported validated media MIME type.'),
        };
    }

    /**
     * @return array{int, int}
     */
    private function imageDimensions(UploadedFile $file): array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $dimensions = getimagesize($file->getPathname());
        } finally {
            restore_error_handler();
        }

        if (! is_array($dimensions)
            || ! isset($dimensions[0], $dimensions[1])
            || $dimensions[0] < 1
            || $dimensions[1] < 1) {
            throw ValidationException::withMessages([
                'file' => ['The file must be a valid image.'],
            ]);
        }

        return [(int) $dimensions[0], (int) $dimensions[1]];
    }

    private function compensateFailedStorage(
        Filesystem $filesystem,
        Media $media,
        Throwable $exception,
    ): void {
        $this->markFailed($media);

        try {
            $fileRemoved = $filesystem->delete($media->path);

            if (! $fileRemoved) {
                $fileRemoved = ! $filesystem->exists($media->path);
            }
        } catch (Throwable $deleteException) {
            $fileRemoved = false;
            report($deleteException);
        }

        $metadataDeleted = $fileRemoved && $this->deleteFailedMetadata($media);

        Log::error('Media file storage failed.', [
            'media_id' => $media->getKey(),
            'disk' => $media->disk,
            'request_id' => Context::get('request_id'),
            'file_removed' => $fileRemoved,
            'metadata_deleted' => $metadataDeleted,
            'exception' => $exception::class,
        ]);
    }

    private function retainFailedMedia(Media $media, Throwable $exception, string $stage): void
    {
        $this->markFailed($media);

        Log::error('Media upload did not complete; metadata was retained.', [
            'media_id' => $media->getKey(),
            'disk' => $media->disk,
            'stage' => $stage,
            'request_id' => Context::get('request_id'),
            'exception' => $exception::class,
        ]);
    }

    private function markFailed(Media $media): void
    {
        try {
            DB::transaction(function () use ($media): void {
                $lockedMedia = Media::query()->lockForUpdate()->find($media->getKey());

                if ($lockedMedia !== null) {
                    $lockedMedia->forceFill(['status' => Media::STATUS_FAILED])->save();
                }
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deleteFailedMetadata(Media $media): bool
    {
        try {
            return DB::transaction(function () use ($media): bool {
                $lockedMedia = Media::query()->lockForUpdate()->find($media->getKey());

                if ($lockedMedia === null) {
                    return true;
                }

                $lockedMedia->delete();

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
