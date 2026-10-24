<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Mitoop\Http\Resources\Resource;

class SystemSettingsResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'basic' => $this->resource['basic'],
            'branding' => $this->resource['branding'],
        ];
    }
}
