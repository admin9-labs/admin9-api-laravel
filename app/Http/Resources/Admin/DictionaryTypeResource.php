<?php

namespace App\Http\Resources\Admin;

use App\Models\DictionaryType;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

/**
 * @mixin DictionaryType
 */
class DictionaryTypeResource extends Resource
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
            'code' => $this->code,
            'description' => $this->description,
            'sort' => $this->sort,
            'is_active' => $this->is_active,
            'items_count' => $this->whenCounted('items'),
            'items' => DictionaryItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
