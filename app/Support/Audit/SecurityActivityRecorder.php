<?php

namespace App\Support\Audit;

use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Spatie\Activitylog\Models\Activity;

class SecurityActivityRecorder
{
    /**
     * Record a credential lifecycle event without storing credential material.
     */
    public function record(Model $subject, Model $causer, string $guard, string $event): ?Activity
    {
        /** @var Activity|null $activity */
        $activity = activity('security')
            ->event($event)
            ->performedOn($subject)
            ->causedBy($causer)
            ->withProperties(SensitiveDataSanitizer::removeSensitiveKeys([
                'request_id' => Context::get('request_id'),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'guard' => $guard,
                'route' => request()?->route()?->getName(),
                'path' => request()?->path(),
                'method' => request()?->method(),
                'event' => $event,
            ]))
            ->log(sprintf('%s %s', class_basename($subject), $event));

        return $activity;
    }
}
