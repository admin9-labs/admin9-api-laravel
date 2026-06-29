<?php

namespace App\Models\Concerns;

use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

trait LogsAdminActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logFillable()
            ->logOnlyDirty()
            ->logExcept($this->sensitiveAuditAttributes())
            ->dontLogIfAttributesChangedOnly(['last_login_at', 'last_login_ip', 'remember_token'])
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => sprintf('%s %s', class_basename($this), $eventName));
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $properties = $activity->properties?->toArray() ?? [];

        $activity->causer_type ??= Auth::guard('admin')->user() instanceof Model
            ? Auth::guard('admin')->user()->getMorphClass()
            : null;
        $activity->causer_id ??= Auth::guard('admin')->id();
        $activity->properties = collect(SensitiveDataSanitizer::removeSensitiveKeys(array_merge($properties, [
            'request_id' => Context::get('request_id'),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'guard' => 'admin',
            'route' => request()?->route()?->getName(),
            'path' => request()?->path(),
            'method' => request()?->method(),
            'event' => $eventName,
        ])));
    }

    /**
     * @return array<int, string>
     */
    protected function sensitiveAuditAttributes(): array
    {
        return ['password', 'remember_token', 'token', 'authorization', 'secret', 'jwt', 'api_key', 'apikey'];
    }
}
