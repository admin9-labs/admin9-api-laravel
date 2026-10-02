<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ManagedSystemSettingException;
use App\Http\Controllers\Controller;
use App\Http\Filters\SystemConfigFilter;
use App\Http\Requests\Admin\StoreSystemConfigRequest;
use App\Http\Requests\Admin\UpdateSystemConfigRequest;
use App\Http\Resources\Admin\SystemConfigResource;
use App\Models\SystemConfig;
use App\Support\SystemSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SystemConfigController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        return $this->success(SystemConfigResource::collection(
            SystemConfig::query()
                ->filter(SystemConfigFilter::class)
                ->ordered()
                ->paginate()
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSystemConfigRequest $request): JsonResponse
    {
        if (SystemSettings::isManagedKey($request->validated('key'))) {
            throw new ManagedSystemSettingException;
        }

        try {
            $systemConfig = DB::transaction(fn (): SystemConfig => SystemConfig::query()->create($request->validated()));
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwKeyConflict($exception, $request->validated('key'));
        }

        return $this->success([
            'system_config' => SystemConfigResource::make($systemConfig),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(SystemConfig $systemConfig): JsonResponse
    {
        return $this->success([
            'system_config' => SystemConfigResource::make($systemConfig),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSystemConfigRequest $request, SystemConfig $systemConfig): JsonResponse
    {
        if (
            SystemSettings::isManagedKey($systemConfig->key)
            || SystemSettings::isManagedKey($request->validated('key'))
        ) {
            throw new ManagedSystemSettingException;
        }

        try {
            $systemConfig = DB::transaction(function () use ($request, $systemConfig): SystemConfig {
                $systemConfig = SystemConfig::query()->lockForUpdate()->findOrFail($systemConfig->getKey());

                if (SystemSettings::isManagedKey($systemConfig->key)) {
                    throw new ManagedSystemSettingException;
                }

                $request->validateCurrentValue($systemConfig);
                $systemConfig->update($request->validated());

                return $systemConfig;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $this->throwKeyConflict($exception, $request->validated('key', $systemConfig->key), $systemConfig);
        }

        return $this->success([
            'system_config' => SystemConfigResource::make($systemConfig->refresh()),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SystemConfig $systemConfig): JsonResponse
    {
        if (SystemSettings::isManagedKey($systemConfig->key)) {
            throw new ManagedSystemSettingException;
        }

        DB::transaction(function () use ($systemConfig): void {
            $systemConfig = SystemConfig::query()->lockForUpdate()->findOrFail($systemConfig->getKey());

            if (SystemSettings::isManagedKey($systemConfig->key)) {
                throw new ManagedSystemSettingException;
            }

            $systemConfig->delete();
        });

        return $this->success(message: 'deleted');
    }

    private function throwKeyConflict(UniqueConstraintViolationException $exception, string $key, ?SystemConfig $systemConfig = null): never
    {
        if (SystemConfig::query()->where('key', $key)
            ->when($systemConfig !== null, fn ($query) => $query->whereKeyNot($systemConfig->getKey()))
            ->exists()) {
            throw ValidationException::withMessages([
                'key' => [__('validation.unique', ['attribute' => 'key'])],
            ]);
        }

        throw $exception;
    }
}
