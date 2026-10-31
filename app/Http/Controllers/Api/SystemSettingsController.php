<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingsResource;
use App\Support\SystemSettings;

class SystemSettingsController extends Controller
{
    public function public(SystemSettings $systemSettings): SystemSettingsResource
    {
        return SystemSettingsResource::make($systemSettings->read());
    }
}
