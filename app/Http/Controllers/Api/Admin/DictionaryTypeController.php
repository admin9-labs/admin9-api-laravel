<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\DictionaryTypeFilter;
use App\Http\Requests\Admin\ListDictionaryTypesRequest;
use App\Http\Requests\Admin\StoreDictionaryTypeRequest;
use App\Http\Requests\Admin\UpdateDictionaryTypeRequest;
use App\Http\Resources\Admin\DictionaryTypeResource;
use App\Models\DictionaryType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DictionaryTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ListDictionaryTypesRequest $request): JsonResponse
    {
        return $this->success(DictionaryTypeResource::collection(
            DictionaryType::query()
                ->withCount('items')
                ->filter(DictionaryTypeFilter::class, $request->validated())
                ->ordered()
                ->paginate()
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDictionaryTypeRequest $request): JsonResponse
    {
        try {
            $dictionaryType = DB::transaction(fn (): DictionaryType => DictionaryType::query()->create($request->validated()));
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwCodeConflict($exception, $request->validated('code'));
        }

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
        try {
            $dictionaryType = DB::transaction(function () use ($request, $dictionaryType): DictionaryType {
                $dictionaryType = DictionaryType::query()->lockForUpdate()->findOrFail($dictionaryType->getKey());
                $dictionaryType->update($request->validated());

                return $dictionaryType;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwCodeConflict($exception, $request->validated('code', $dictionaryType->code), $dictionaryType);
        }

        return $this->success([
            'dictionary_type' => DictionaryTypeResource::make($dictionaryType->refresh()->load('items')->loadCount('items')),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DictionaryType $dictionaryType): JsonResponse
    {
        DB::transaction(function () use ($dictionaryType): void {
            $dictionaryType = DictionaryType::query()->lockForUpdate()->findOrFail($dictionaryType->getKey());

            if ($dictionaryType->items()->exists()) {
                throw ValidationException::withMessages([
                    'dictionary_type' => ['Dictionary types with items cannot be deleted.'],
                ]);
            }

            $dictionaryType->delete();
        }, attempts: 3);

        return $this->success(message: 'deleted');
    }

    private function throwCodeConflict(UniqueConstraintViolationException $exception, string $code, ?DictionaryType $dictionaryType = null): never
    {
        if (DictionaryType::query()->where('code', $code)
            ->when($dictionaryType !== null, fn ($query) => $query->whereKeyNot($dictionaryType->getKey()))
            ->exists()) {
            throw ValidationException::withMessages([
                'code' => [__('validation.unique', ['attribute' => 'code'])],
            ]);
        }

        throw $exception;
    }
}
