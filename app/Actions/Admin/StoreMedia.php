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
        $disk = (string) config('filesystems.media', 'public');
        $filesystem = $this->filesystems->disk($disk);
        $path = $filesystem->putFileAs($directory, $file, $filename, ['visibility' => 'public']);

        if ($path === false) {
            throw new RuntimeException('Media file could not be stored.');
        }

        try {
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
                    'created_by' => $actor->getKey(),
                ])->save();
                $this->activityRecorder->record($media, $actor, 'admin', 'media_uploaded');

                return $media;
            });
        } catch (Throwable $exception) {
            $this->compensateFailedPersistence($filesystem, $disk, $path, $exception);

            throw $exception;
        }
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

    private function compensateFailedPersistence(
        Filesystem $filesystem,
        string $disk,
        string $path,
        Throwable $exception,
    ): void {
        try {
            $deleted = $filesystem->delete($path);
        } catch (Throwable $deleteException) {
            $deleted = false;
            report($deleteException);
        }

        Log::error('Media metadata persistence failed after file upload.', [
            'disk' => $disk,
            'request_id' => Context::get('request_id'),
            'compensation_deleted' => $deleted,
            'exception' => $exception::class,
        ]);
    }
}
