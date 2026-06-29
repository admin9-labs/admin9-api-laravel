<?php

namespace App\Http\Requests\Admin;

use App\Models\SystemConfig;
use Illuminate\Validation\Rule;

class StoreSystemConfigRequest extends SystemConfigRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'key' => ['required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_\.\-]*$/', Rule::unique(SystemConfig::class, 'key')],
            'value' => $this->valueRules(),
            'type' => ['sometimes', 'required', 'string', Rule::in(SystemConfig::allowedTypes())],
            'config_group' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_\.\-]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
