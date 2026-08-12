<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

#[FailOnUnknownFields]
class UpdateBrandingSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'navigation_logo_url' => ['present', 'nullable', 'string', 'url'],
            'login_logo_url' => ['present', 'nullable', 'string', 'url'],
            'login_background_url' => ['present', 'nullable', 'string', 'url'],
            'favicon_url' => ['present', 'nullable', 'string', 'url'],
        ];
    }
}
