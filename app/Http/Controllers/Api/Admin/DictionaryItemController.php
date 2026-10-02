<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\DictionaryItemFilter;
use App\Http\Requests\Admin\StoreDictionaryItemRequest;
use App\Http\Requests\Admin\UpdateDictionaryItemRequest;
use App\Http\Resources\Admin\DictionaryItemResource;
use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $dictionaryItem = DB::transaction(function () use ($request): DictionaryItem {
            $this->lockDictionaryType((int) $request->validated('dictionary_type_id'));
            $this->validateUniqueCode($request->validated('dictionary_type_id'), $request->validated('code'));

            return DictionaryItem::query()->create($request->validated());
        }, attempts: 3);

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
        $dictionaryItem = DB::transaction(function () use ($request, $dictionaryItem): DictionaryItem {
            $dictionaryItem = DictionaryItem::query()->lockForUpdate()->findOrFail($dictionaryItem->getKey());
            $typeId = (int) $request->validated('dictionary_type_id', $dictionaryItem->dictionary_type_id);
            $this->lockDictionaryType($typeId);
            $this->validateUniqueCode($typeId, $request->validated('code', $dictionaryItem->code), $dictionaryItem);
            $dictionaryItem->update($request->validated());

            return $dictionaryItem;
        }, attempts: 3);

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

    private function lockDictionaryType(int $id): void
    {
        if (! DictionaryType::query()->lockForUpdate()->find($id)) {
            throw ValidationException::withMessages([
                'dictionary_type_id' => [__('validation.exists', ['attribute' => 'dictionary type id'])],
            ]);
        }
    }

    private function validateUniqueCode(int $typeId, string $code, ?DictionaryItem $item = null): void
    {
        Validator::make(['code' => $code], [
            'code' => [Rule::unique(DictionaryItem::class, 'code')->where('dictionary_type_id', $typeId)->ignore($item)],
        ])->validate();
    }
}
