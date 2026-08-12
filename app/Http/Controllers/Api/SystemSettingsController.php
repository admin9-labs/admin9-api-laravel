<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingsResource;
use App\Support\SystemSettings;
use Illuminate\Http\JsonResponse;

class SystemSettingsController extends Controller
{
    public function public(SystemSettings $systemSettings): JsonResponse
    {
        return $this->success(SystemSettingsResource::make($systemSettings->read()));
    }
}
