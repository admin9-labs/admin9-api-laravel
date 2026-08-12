<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBasicSystemSettingsRequest;
use App\Http\Requests\Admin\UpdateBrandingSystemSettingsRequest;
use App\Http\Resources\SystemSettingsResource;
use App\Support\SystemSettings;
use Illuminate\Http\JsonResponse;

class SystemSettingsController extends Controller
{
    public function show(SystemSettings $systemSettings): JsonResponse
    {
        return $this->success(SystemSettingsResource::make($systemSettings->read()));
    }

    public function updateBasic(
        UpdateBasicSystemSettingsRequest $request,
        SystemSettings $systemSettings,
    ): JsonResponse {
        $systemSettings->updateBasic($request->validated());

        return $this->success(SystemSettingsResource::make($systemSettings->read()));
    }

    public function updateBranding(
        UpdateBrandingSystemSettingsRequest $request,
        SystemSettings $systemSettings,
    ): JsonResponse {
        $systemSettings->updateBranding($request->validated());

        return $this->success(SystemSettingsResource::make($systemSettings->read()));
    }
}
