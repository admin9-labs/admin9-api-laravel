<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\SystemConfigFilter;
use App\Http\Requests\Admin\StoreSystemConfigRequest;
use App\Http\Requests\Admin\UpdateSystemConfigRequest;
use App\Http\Resources\Admin\SystemConfigResource;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        $systemConfig = DB::transaction(fn (): SystemConfig => SystemConfig::query()->create($request->validated()));

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
        DB::transaction(function () use ($request, $systemConfig): void {
            $systemConfig->update($request->validated());
        });

        return $this->success([
            'system_config' => SystemConfigResource::make($systemConfig->refresh()),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SystemConfig $systemConfig): JsonResponse
    {
        DB::transaction(function () use ($systemConfig): void {
            $systemConfig->delete();
        });

        return $this->success(message: 'deleted');
    }
}
