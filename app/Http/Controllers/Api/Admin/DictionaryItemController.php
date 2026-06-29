<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\DictionaryItemFilter;
use App\Http\Requests\Admin\StoreDictionaryItemRequest;
use App\Http\Requests\Admin\UpdateDictionaryItemRequest;
use App\Http\Resources\Admin\DictionaryItemResource;
use App\Models\DictionaryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DictionaryItemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return $this->success(DictionaryItemResource::collection(
            DictionaryItem::query()
                ->with('type')
                ->filter(DictionaryItemFilter::class)
                ->ordered()
                ->paginate()
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDictionaryItemRequest $request): JsonResponse
    {
        $dictionaryItem = DB::transaction(fn (): DictionaryItem => DictionaryItem::query()->create($request->validated()));

        return $this->success([
            'dictionary_item' => DictionaryItemResource::make($dictionaryItem->load('type')),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(DictionaryItem $dictionaryItem): JsonResponse
    {
        return $this->success([
            'dictionary_item' => DictionaryItemResource::make($dictionaryItem->load('type')),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDictionaryItemRequest $request, DictionaryItem $dictionaryItem): JsonResponse
    {
        DB::transaction(function () use ($request, $dictionaryItem): void {
            $dictionaryItem->update($request->validated());
        });

        return $this->success([
            'dictionary_item' => DictionaryItemResource::make($dictionaryItem->refresh()->load('type')),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DictionaryItem $dictionaryItem): JsonResponse
    {
        DB::transaction(function () use ($dictionaryItem): void {
            $dictionaryItem->delete();
        });

        return $this->success(message: 'deleted');
    }
}
