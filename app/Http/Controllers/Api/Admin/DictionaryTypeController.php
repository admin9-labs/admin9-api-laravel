<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\DictionaryTypeFilter;
use App\Http\Requests\Admin\StoreDictionaryTypeRequest;
use App\Http\Requests\Admin\UpdateDictionaryTypeRequest;
use App\Http\Resources\Admin\DictionaryTypeResource;
use App\Models\DictionaryType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DictionaryTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return $this->success(DictionaryTypeResource::collection(
            DictionaryType::query()
                ->withCount('items')
                ->filter(DictionaryTypeFilter::class)
                ->ordered()
                ->paginate()
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDictionaryTypeRequest $request): JsonResponse
    {
        $dictionaryType = DB::transaction(fn (): DictionaryType => DictionaryType::query()->create($request->validated()));

        return $this->success([
            'dictionary_type' => DictionaryTypeResource::make($dictionaryType->load('items')->loadCount('items')),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(DictionaryType $dictionaryType): JsonResponse
    {
        return $this->success([
            'dictionary_type' => DictionaryTypeResource::make($dictionaryType->load(['items'])->loadCount('items')),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDictionaryTypeRequest $request, DictionaryType $dictionaryType): JsonResponse
    {
        DB::transaction(function () use ($request, $dictionaryType): void {
            $dictionaryType->update($request->validated());
        });

        return $this->success([
            'dictionary_type' => DictionaryTypeResource::make($dictionaryType->refresh()->load('items')->loadCount('items')),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DictionaryType $dictionaryType): JsonResponse
    {
        if ($dictionaryType->items()->exists()) {
            return $this->error('Dictionary types with items cannot be deleted.', 422);
        }

        DB::transaction(function () use ($dictionaryType): void {
            $dictionaryType->delete();
        });

        return $this->success(message: 'deleted');
    }
}
