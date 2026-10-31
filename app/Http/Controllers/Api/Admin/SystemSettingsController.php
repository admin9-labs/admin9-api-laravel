<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBasicSystemSettingsRequest;
use App\Http\Requests\Admin\UpdateBrandingSystemSettingsRequest;
use App\Http\Resources\SystemSettingsResource;
use App\Support\SystemSettings;

class SystemSettingsController extends Controller
{
    public function show(SystemSettings $systemSettings): SystemSettingsResource
    {
        return SystemSettingsResource::make($systemSettings->read());
    }

    public function updateBasic(
        UpdateBasicSystemSettingsRequest $request,
        SystemSettings $systemSettings,
    ): SystemSettingsResource {
        $systemSettings->updateBasic($request->validated());

        return SystemSettingsResource::make($systemSettings->read());
    }

    public function updateBranding(
        UpdateBrandingSystemSettingsRequest $request,
        SystemSettings $systemSettings,
    ): SystemSettingsResource {
        $systemSettings->updateBranding($request->validated());

        return SystemSettingsResource::make($systemSettings->read());
    }
}
