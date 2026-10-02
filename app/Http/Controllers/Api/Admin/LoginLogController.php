<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\LoginLogFilter;
use App\Http\Requests\Admin\ListLoginLogsRequest;
use App\Http\Resources\Admin\LoginLogResource;
use App\Models\LoginLog;
use Illuminate\Http\JsonResponse;

class LoginLogController extends Controller
{
    public function index(ListLoginLogsRequest $request): JsonResponse
    {
        return $this->success(LoginLogResource::collection(
            LoginLog::query()
                ->filter(LoginLogFilter::class, $request->validated())
                ->latestFirst()
                ->paginate()
        ));
    }
}
