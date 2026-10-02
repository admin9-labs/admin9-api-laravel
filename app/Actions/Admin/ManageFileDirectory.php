<?php

namespace App\Actions\Admin;

use App\Exceptions\FileDirectoryNotEmptyException;
use App\Models\FileDirectory;
use App\Models\User;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFileDirectory
{
    public function __construct(private SecurityActivityRecorder $activityRecorder) {}

    /** @param array{name: string, parent_id?: ?int} $attributes */
    public function create(array $attributes, User $actor): FileDirectory
    {
        try {
            return DB::transaction(function () use ($actor, $attributes): FileDirectory {
                $parentId = $attributes['parent_id'] ?? null;

                if ($parentId !== null) {
                    $parent = FileDirectory::query()->lockForUpdate()->find($parentId);

                    if (! $parent instanceof FileDirectory) {
                        throw ValidationException::withMessages([
                            'parent_id' => ['The selected parent directory no longer exists.'],
                        ]);
                    }

                    if ($parent->parent_id !== null) {
                        throw ValidationException::withMessages([
                            'parent_id' => ['File directories may contain at most two levels.'],
                        ]);
                    }
                }

                if (FileDirectory::query()->where('parent_id', $parentId)->where('name', $attributes['name'])->exists()) {
                    throw ValidationException::withMessages([
                        'name' => ['The name has already been taken within this directory.'],
                    ]);
                }

                $directory = new FileDirectory;
                $directory->forceFill([
                    'parent_id' => $parentId,
                    'scope_key' => $parentId === null ? 'root' : "parent:{$parentId}",
                    'name' => $attributes['name'],
                ])->save();
                $this->activityRecorder->record($directory, $actor, 'admin', 'file_directory_created');

                return $directory;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'name' => ['The name has already been taken within this directory.'],
            ]);
        }
    }

    public function delete(FileDirectory $directory, User $actor): void
    {
        try {
            DB::transaction(function () use ($actor, $directory): void {
                $lockedDirectory = FileDirectory::query()->lockForUpdate()->find($directory->getKey());

                if (! $lockedDirectory instanceof FileDirectory) {
                    throw (new ModelNotFoundException)->setModel(FileDirectory::class, [$directory->getKey()]);
                }

                if ($lockedDirectory->children()->exists() || $lockedDirectory->files()->exists()) {
                    throw new FileDirectoryNotEmptyException;
                }

                $this->activityRecorder->record($lockedDirectory, $actor, 'admin', 'file_directory_deleted');
                $lockedDirectory->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23503'
                && ($exception->errorInfo[1] ?? null) !== 1451
                && ! str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw $exception;
            }

            throw new FileDirectoryNotEmptyException;
        }
    }
}
