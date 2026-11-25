<?php

namespace App\Http\Resources\Admin;

use App\Models\SystemConfig;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

/**
 * @mixin SystemConfig
 */
class SystemConfigResource extends Resource
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
            'name' => $this->name,
            'key' => $this->key,
            'value' => $this->resolvedValue(),
            'type' => $this->type,
            'config_group' => $this->config_group,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'is_active' => $this->is_active,
            'sort' => $this->sort,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
