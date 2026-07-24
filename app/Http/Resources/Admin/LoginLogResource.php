<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Http\Request;

class LoginLogResource extends PaginationAwareJsonResource
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
            'created_at' => $this->dateTimeString($this->created_at),
        ];
    }
}
