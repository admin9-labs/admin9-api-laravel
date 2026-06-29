<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class RoleResource extends PaginationAwareJsonResource
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
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
