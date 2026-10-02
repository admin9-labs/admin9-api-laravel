<?php

namespace App\Actions\Admin;

use App\Models\File;
use App\Models\FileDirectory;
use App\Models\User;
use App\Support\Admin\AdminPermissionChecker;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveFile
{
    public function __construct(
        private AdminPermissionChecker $permissionChecker,
        private SecurityActivityRecorder $activityRecorder,
    ) {}

    public function handle(File $file, ?int $directoryId, User $actor): void
    {
        if (! $this->permissionChecker->canAccess($actor, 'system.file.update')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($file, $directoryId, $actor): void {
            if ($directoryId !== null && ! FileDirectory::query()->lockForUpdate()->find($directoryId)) {
                throw ValidationException::withMessages([
                    'directory_id' => ['The selected file directory no longer exists.'],
                ]);
            }

            $lockedFile = File::query()->lockForUpdate()->findOrFail($file->getKey());
            if ($lockedFile->status !== File::STATUS_READY || $lockedFile->deletion_token !== null) {
                throw ValidationException::withMessages([
                    'file' => ['Only ready files without a pending deletion can be moved.'],
                ]);
            }

            $previousDirectoryId = $lockedFile->directory_id;
            if ($previousDirectoryId === $directoryId) {
                return;
            }

            $lockedFile->forceFill(['directory_id' => $directoryId])->save();
            $this->activityRecorder->record($lockedFile, $actor, 'admin', 'file_moved', [
                'previous_directory_id' => $previousDirectoryId,
                'directory_id' => $directoryId,
            ]);
        }, attempts: 3);
    }
}
