<?php

namespace App\Http\Requests\Admin;

use App\Models\SystemConfig;
use Illuminate\Validation\Rule;

class UpdateSystemConfigRequest extends SystemConfigRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $systemConfig = $this->systemConfig();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'key' => ['sometimes', 'required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_\.\-]*$/', Rule::unique(SystemConfig::class, 'key')->ignore($systemConfig)],
            'value' => $this->valueRules($systemConfig),
            'type' => ['sometimes', 'required', 'string', Rule::in(SystemConfig::allowedTypes())],
            'config_group' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_\.\-]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
