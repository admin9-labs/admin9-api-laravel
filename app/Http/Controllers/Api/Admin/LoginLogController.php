<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\LoginLogFilter;
use App\Http\Requests\Admin\ListLoginLogsRequest;
use App\Http\Resources\Admin\LoginLogResourceCollection;
use App\Models\LoginLog;

class LoginLogController extends Controller
{
    public function index(ListLoginLogsRequest $request): LoginLogResourceCollection
    {
        return new LoginLogResourceCollection(
            LoginLog::query()
                ->filter(LoginLogFilter::class, $request->validated())
                ->latestFirst()
                ->paginate()
        );
    }
}
