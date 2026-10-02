<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ManageFileDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFileDirectoryRequest;
use App\Http\Resources\Admin\FileDirectoryResource;
use App\Models\FileDirectory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileDirectoryController extends Controller
{
    public function __construct(private ManageFileDirectory $manageFileDirectory) {}

    public function index(): JsonResponse
    {
        $directories = FileDirectory::query()->orderByRaw('parent_id IS NOT NULL')->orderBy('parent_id')->orderBy('id')->get();

        return $this->success(FileDirectoryResource::collection($directories));
    }

    public function store(StoreFileDirectoryRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $directory = $this->manageFileDirectory->create($request->validated(), $actor);

        return $this->success(['directory' => FileDirectoryResource::make($directory)]);
    }

    public function destroy(FileDirectory $fileDirectory, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $this->manageFileDirectory->delete($fileDirectory, $actor);

        return $this->success(message: 'file directory deleted');
    }
}
