<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use Illuminate\Http\Request;

class FileDirectoryResource extends PaginationAwareJsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'parent_id' => $this->parent_id === null ? null : (int) $this->parent_id,
            'name' => (string) $this->name,
            'created_at' => $this->dateTimeString($this->created_at),
            'updated_at' => $this->dateTimeString($this->updated_at),
        ];
    }
}
