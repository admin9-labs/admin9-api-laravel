<?php

namespace App\Http\Resources\Admin;

use App\Support\Security\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
class ActivityLogResource extends Resource
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
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'properties' => SensitiveDataSanitizer::removeSensitiveKeys($this->properties?->toArray() ?? []),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
