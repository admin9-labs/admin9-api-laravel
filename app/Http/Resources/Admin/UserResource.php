<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class UserResource extends PaginationAwareJsonResource
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
            'email' => $this->email,
            'is_active' => $this->is_active,
            'last_login_at' => $this->dateTimeString($this->last_login_at),
            'last_login_ip' => $this->last_login_ip,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
