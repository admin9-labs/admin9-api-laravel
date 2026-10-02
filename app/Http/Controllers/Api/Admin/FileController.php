<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\DeleteFile;
use App\Actions\Admin\MoveFile;
use App\Actions\Admin\StoreFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteFileByUrlRequest;
use App\Http\Requests\Admin\ListFileRequest;
use App\Http\Requests\Admin\StoreFileRequest;
use App\Http\Requests\Admin\UpdateFileByUrlRequest;
use App\Http\Requests\Admin\UpdateFileRequest;
use App\Http\Resources\Admin\FileResource;
use App\Models\File;
use App\Models\FileDirectory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('deletion_token')
                ->orWhereNull('deletion_started_at')
                ->orWhere('deletion_started_at', '<=', now()->subMinutes(File::DELETION_CLAIM_TTL_MINUTES)))
            ->when(is_string($search) && $search !== '', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"))
            ->when(is_string($type) && $type !== '', fn (Builder $query): Builder => $query->where('type', $type))
            ->when(isset($validated['types']), fn (Builder $query): Builder => $query->whereIn('type', $validated['types']))
            ->when($request->boolean('ungrouped'), fn (Builder $query): Builder => $query->whereNull('directory_id'))
            ->when(isset($validated['directory_id']), fn (Builder $query): Builder => $query->where('directory_id', $validated['directory_id']))
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
        $directoryId = $request->validated('directory_id');
        $directory = $directoryId === null ? null : FileDirectory::query()->findOrFail($directoryId);
        $file = $this->storeFile->handle($uploadedFile, $actor, $directory);

        return $this->success(['file' => FileResource::make($file)]);
    }

    public function update(File $file, UpdateFileRequest $request, MoveFile $moveFile): JsonResponse
    {
        $directoryId = $request->validated('directory_id');
        $moveFile->handle($file, $directoryId === null ? null : (int) $directoryId, $request->user('admin'));

        return $this->success(message: 'file moved');
    }

    public function updateByUrl(UpdateFileByUrlRequest $request, MoveFile $moveFile): JsonResponse
    {
        $directoryId = $request->validated('directory_id');
        $moveFile->handle($this->fileByUrl($request->validated('url')), $directoryId === null ? null : (int) $directoryId, $request->user('admin'));

        return $this->success(message: 'file moved');
    }

    public function destroyByUrl(DeleteFileByUrlRequest $request): JsonResponse
    {
        $this->deleteFile->handle($this->fileByUrl($request->validated('url')), $request->user('admin'));

        return $this->success(message: 'deleted');
    }

    private function fileByUrl(string $url): File
    {
        $prefix = rtrim(Storage::disk('public')->url(''), '/').'/';
        abort_unless(str_starts_with($url, $prefix), 404);

        return File::query()->where('disk', 'public')->where('path', substr($url, strlen($prefix)))->firstOrFail();
    }

    public function destroy(File $file, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $this->deleteFile->handle($file, $actor);

        return $this->success(message: 'deleted');
    }
}
