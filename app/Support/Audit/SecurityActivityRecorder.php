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
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(
        Model $subject,
        ?Model $causer,
        string $guard,
        string $event,
        array $properties = [],
    ): ?Activity {
        /** @var Activity|null $activity */
        $logger = activity('security')
            ->event($event)
            ->performedOn($subject)
            ->withProperties(SensitiveDataSanitizer::removeSensitiveKeys(array_merge($properties, [
                'request_id' => Context::get('request_id'),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'guard' => $guard,
                'route' => request()?->route()?->getName(),
                'path' => request()?->path(),
                'method' => request()?->method(),
                'event' => $event,
            ])));

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        $activity = $logger->log(sprintf('%s %s', class_basename($subject), $event));

        return $activity;
    }
}
