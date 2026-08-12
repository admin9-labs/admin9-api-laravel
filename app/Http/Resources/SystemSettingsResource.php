<?php

namespace App\Http\Resources;

use App\Http\Resources\Admin\MediaResource;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'basic' => $this->resource['basic'],
            'branding' => collect($this->resource['branding'])
                ->map(fn (array $setting): array => [
                    'media_id' => $setting['media_id'],
                    'state' => $setting['state'],
                    'media' => $setting['media'] instanceof Media
                        ? MediaResource::make($setting['media'])
                        : null,
                ])
                ->all(),
        ];
    }
}
