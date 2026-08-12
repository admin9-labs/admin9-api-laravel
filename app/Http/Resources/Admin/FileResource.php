<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaginationAwareJsonResource;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileResource extends PaginationAwareJsonResource
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
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size' => $this->size,
            'url' => $this->status === File::STATUS_READY
                ? Storage::disk($this->disk)->url($this->path)
                : null,
            'width' => $this->width,
            'height' => $this->height,
            'status' => $this->status,
            'created_at' => $this->dateTimeString($this->created_at),
        ];
    }
}
