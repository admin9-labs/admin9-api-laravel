<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Filters\ActivityLogFilter;
use App\Http\Requests\Admin\ListActivityLogsRequest;
use App\Http\Resources\Admin\ActivityLogResource;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(ListActivityLogsRequest $request): JsonResponse
    {
        $pageSize = min(max(request()->integer('page_size', 15), 1), 100);

        return $this->success(ActivityLogResource::collection(
            Activity::query()
                ->filter(ActivityLogFilter::class, $request->validated())
                ->orderByDesc('id')
                ->paginate($pageSize)
        ));
    }
}
