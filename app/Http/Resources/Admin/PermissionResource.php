<?php

namespace App\Http\Resources\Admin;

use App\Models\Permission;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

/**
 * @mixin Permission
 */
class PermissionResource extends Resource
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
            'guard_name' => $this->guard_name,
            'display_name' => $this->display_name,
            'group' => $this->group,
            'description' => $this->description,
            'sort' => $this->sort,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
