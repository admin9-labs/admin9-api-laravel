<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class MenuResource extends PaginationAwareJsonResource
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
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'code' => $this->code,
            'path' => $this->path,
            'component' => $this->component,
            'icon' => $this->icon,
            'type' => $this->type,
            'permission_id' => $this->permission_id,
            'permission_name' => $this->permission_name,
            'permission' => PermissionResource::make($this->whenLoaded('permission')),
            'sort' => $this->sort,
            'is_visible' => $this->is_visible,
            'is_active' => $this->is_active,
            'children' => MenuResource::collection($this->whenLoaded('children')),
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
