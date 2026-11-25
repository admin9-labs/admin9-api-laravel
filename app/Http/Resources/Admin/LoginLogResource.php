<?php

namespace App\Http\Resources\Admin;

use App\Models\LoginLog;
use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

/**
 * @mixin LoginLog
 */
class LoginLogResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guard' => $this->guard,
            'account' => $this->account,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'event' => $this->event,
            'successful' => $this->successful,
            'failure_reason' => $this->failure_reason,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'context' => SensitiveDataSanitizer::removeSensitiveKeys($this->context ?? []),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
