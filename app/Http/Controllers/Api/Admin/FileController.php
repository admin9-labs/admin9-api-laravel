<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\DeleteFile;
use App\Actions\Admin\StoreFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListFileRequest;
use App\Http\Requests\Admin\StoreFileRequest;
use App\Http\Resources\Admin\FileResource;
use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class FileController extends Controller
{
    public function __construct(
        private StoreFile $storeFile,
        private DeleteFile $deleteFile,
    ) {}

    public function index(ListFileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $type = $validated['type'] ?? null;
        $files = File::query()
            ->whereNull('deletion_token')
            ->when(is_string($search) && $search !== '', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"))
            ->when(is_string($type) && $type !== '', fn (Builder $query): Builder => $query->where('type', $type))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? null);

        return $this->success(FileResource::collection($files));
    }

    public function store(StoreFileRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->validated('file');
        $file = $this->storeFile->handle($uploadedFile, $actor);

        return $this->success(['file' => FileResource::make($file)]);
    }

    public function destroy(File $file, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $this->deleteFile->handle($file, $actor);

        return $this->success(message: 'deleted');
    }
}
