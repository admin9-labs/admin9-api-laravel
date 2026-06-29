<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class DictionaryItemResource extends PaginationAwareJsonResource
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
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
