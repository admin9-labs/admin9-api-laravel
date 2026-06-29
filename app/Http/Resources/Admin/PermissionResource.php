<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class PermissionResource extends PaginationAwareJsonResource
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
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
