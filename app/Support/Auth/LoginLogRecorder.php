<?php

namespace App\Support\Auth;

use App\Models\LoginLog;
use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class LoginLogRecorder
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        Request $request,
        string $guard,
        string $event,
        bool $successful,
        ?string $account = null,
        ?Authenticatable $subject = null,
        ?string $failureReason = null,
        array $context = []
    ): LoginLog {
        $subjectAttributes = [];
        if ($subject instanceof Model) {
            $subjectAttributes = [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ];
        }

        return LoginLog::query()->create(array_merge([
            'guard' => $guard,
            'account' => $account,
            'event' => $event,
            'successful' => $successful,
            'failure_reason' => $failureReason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => Context::get('request_id'),
            'context' => SensitiveDataSanitizer::removeSensitiveKeys(array_merge([
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'method' => $request->method(),
            ], $context)),
        ], $subjectAttributes));
    }
}
