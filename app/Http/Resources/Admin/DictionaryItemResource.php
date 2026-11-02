<?php

namespace App\Http\Resources\Admin;

use App\Models\DictionaryItem;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

/**
 * @mixin DictionaryItem
 */
class DictionaryItemResource extends Resource
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
            'dictionary_type_id' => $this->dictionary_type_id,
            'name' => $this->name,
            'code' => $this->code,
            'value' => $this->value,
            'description' => $this->description,
            'meta' => $this->meta,
            'sort' => $this->sort,
            'is_active' => $this->is_active,
            'type' => DictionaryTypeResource::make($this->whenLoaded('type')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
