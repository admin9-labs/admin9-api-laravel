<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\DeleteMedia;
use App\Actions\Admin\StoreMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListMediaRequest;
use App\Http\Requests\Admin\StoreMediaRequest;
use App\Http\Resources\Admin\MediaResource;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MediaController extends Controller
{
    public function __construct(
        private StoreMedia $storeMedia,
        private DeleteMedia $deleteMedia,
    ) {}

    public function index(ListMediaRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $media = Media::query()
            ->whereNull('deletion_token')
            ->when(is_string($search) && $search !== '', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? null);

        return $this->success(MediaResource::collection($media));
    }

    public function store(StoreMediaRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        /** @var UploadedFile $file */
        $file = $request->validated('file');
        $media = $this->storeMedia->handle($file, $actor);

        return $this->success(['media' => MediaResource::make($media)]);
    }

    public function destroy(Media $media, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('admin');
        $this->deleteMedia->handle($media, $actor);

        return $this->success(message: 'deleted');
    }
}
