<?php

namespace App\Actions\Admin;

use App\Models\File;
use App\Models\User;
use App\Support\Audit\SecurityActivityRecorder;
use App\Support\FileUploadPolicy;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreFile
{
    private const DISK = 'public';

    public function __construct(
        private FilesystemFactory $filesystems,
        private SecurityActivityRecorder $activityRecorder,
        private FileUploadPolicy $uploadPolicy,
    ) {}

    public function handle(UploadedFile $file, User $actor): File
    {
        $metadata = $this->uploadPolicy->inspect($file);
        $directory = 'files/'.now()->format('Y/m');
        $filename = Str::uuid().'.'.$metadata['extension'];
        $path = $directory.'/'.$filename;
        $disk = self::DISK;
        $filesystem = $this->filesystems->disk($disk);
        $fileRecord = $this->createPendingFile($file, $actor, $disk, $path, $metadata);
        $fileWasStored = false;

        try {
            $storedFile = DB::transaction(function () use ($actor, $file, $fileRecord, $filesystem, &$fileWasStored, $path): ?File {
                $lockedFile = File::query()->lockForUpdate()->findOrFail($fileRecord->getKey());

                if ($lockedFile->status !== File::STATUS_PENDING || $lockedFile->deletion_token !== null) {
                    return null;
                }

                $stream = fopen($file->getPathname(), 'rb');

                if ($stream === false) {
                    throw new RuntimeException('File stream could not be opened.');
                }

                try {
                    $fileWasStored = $filesystem->writeStream($path, $stream, ['visibility' => 'public']);
                } finally {
                    fclose($stream);
                }

                if (! $fileWasStored) {
                    throw new RuntimeException('File could not be stored.');
                }

                $lockedFile->forceFill(['status' => File::STATUS_READY])->save();
                $this->activityRecorder->record($lockedFile, $actor, 'admin', 'file_uploaded');

                return $lockedFile;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($fileWasStored) {
                $this->retainFailedFile($fileRecord, $exception, 'metadata_finalization');
            } else {
                $this->compensateFailedStorage($filesystem, $fileRecord, $exception);
            }

            throw $exception;
        }

        if ($storedFile === null) {
            throw new RuntimeException('File upload lease expired before the file was stored.');
        }

        return $storedFile;
    }

    private function createPendingFile(
        UploadedFile $file,
        User $actor,
        string $disk,
        string $path,
        /** @var array{type: string, mime_type: string, extension: string, size: int, width: ?int, height: ?int} $metadata */
        array $metadata,
    ): File {
        return DB::transaction(function () use ($actor, $disk, $file, $metadata, $path): File {
            $fileRecord = new File;
            $fileRecord->forceFill([
                'name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'type' => $metadata['type'],
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $metadata['mime_type'],
                'extension' => $metadata['extension'],
                'size' => $metadata['size'],
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'status' => File::STATUS_PENDING,
                'created_by' => $actor->getKey(),
            ])->save();

            return $fileRecord;
        }, attempts: 3);
    }

    private function compensateFailedStorage(
        Filesystem $filesystem,
        File $file,
        Throwable $exception,
    ): void {
        $this->markFailed($file);

        try {
            $fileRemoved = $filesystem->delete($file->path);

            if (! $fileRemoved) {
                $fileRemoved = ! $filesystem->exists($file->path);
            }
        } catch (Throwable $deleteException) {
            $fileRemoved = false;
            report($deleteException);
        }

        $metadataDeleted = $fileRemoved && $this->deleteFailedMetadata($file);

        Log::error('File storage failed.', [
            'file_id' => $file->getKey(),
            'disk' => $file->disk,
            'request_id' => Context::get('request_id'),
            'file_removed' => $fileRemoved,
            'metadata_deleted' => $metadataDeleted,
            'exception' => $exception::class,
        ]);
    }

    private function retainFailedFile(File $file, Throwable $exception, string $stage): void
    {
        $this->markFailed($file);

        Log::error('File upload did not complete; metadata was retained.', [
            'file_id' => $file->getKey(),
            'disk' => $file->disk,
            'stage' => $stage,
            'request_id' => Context::get('request_id'),
            'exception' => $exception::class,
        ]);
    }

    private function markFailed(File $file): void
    {
        try {
            DB::transaction(function () use ($file): void {
                $lockedFile = File::query()->lockForUpdate()->find($file->getKey());

                if ($lockedFile !== null) {
                    $lockedFile->forceFill(['status' => File::STATUS_FAILED])->save();
                }
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deleteFailedMetadata(File $file): bool
    {
        try {
            return DB::transaction(function () use ($file): bool {
                $lockedFile = File::query()->lockForUpdate()->find($file->getKey());

                if ($lockedFile === null) {
                    return true;
                }

                $lockedFile->delete();

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
