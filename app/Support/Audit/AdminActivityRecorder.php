<?php

namespace App\Support\Audit;

use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Spatie\Activitylog\Models\Activity;

class AdminActivityRecorder
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(Model $subject, string $event, array $properties = []): ?Activity
    {
        /** @var Activity|null $activity */
        $activity = activity('admin')
            ->event($event)
            ->performedOn($subject)
            ->causedBy(Auth::guard('admin')->user())
            ->withProperties(SensitiveDataSanitizer::removeSensitiveKeys(array_merge($properties, [
                'request_id' => Context::get('request_id'),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'guard' => 'admin',
                'route' => request()?->route()?->getName(),
                'path' => request()?->path(),
                'method' => request()?->method(),
                'event' => $event,
            ])))
            ->log(sprintf('%s %s', class_basename($subject), $event));

        return $activity;
    }
}
