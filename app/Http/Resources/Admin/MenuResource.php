<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MenuResource extends PaginationAwareJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Permission> $permissions */
        $permissions = $this->resource->relationLoaded('permissions')
            ? $this->resource->getRelation('permissions')->sortBy([['sort', 'asc'], ['name', 'asc']])->values()
            : collect();

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'code' => $this->code,
            'path' => $this->path,
            'component' => $this->component,
            'icon' => $this->icon,
            'type' => $this->type,
            'permission_ids' => $permissions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'permission_names' => $permissions->pluck('name')->values()->all(),
            'permissions' => PermissionResource::collection($permissions),
            'sort' => $this->sort,
            'is_visible' => $this->is_visible,
            'is_active' => $this->is_active,
            'children' => MenuResource::collection($this->whenLoaded('children')),
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
