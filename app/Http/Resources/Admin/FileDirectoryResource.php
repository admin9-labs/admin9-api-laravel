<?php

namespace App\Http\Resources\Admin;

use App\Models\FileDirectory;
use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

/**
 * @mixin FileDirectory
 */
class FileDirectoryResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'parent_id' => $this->parent_id === null ? null : (int) $this->parent_id,
            'name' => (string) $this->name,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
