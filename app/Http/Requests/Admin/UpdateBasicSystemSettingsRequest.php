<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

#[FailOnUnknownFields]
class UpdateBasicSystemSettingsRequest extends FormRequest
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
            'system_name' => ['required', 'string', 'max:100'],
            'copyright' => ['present', 'nullable', 'string', 'max:1000'],
            'icp_filing_number' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['system_name', 'copyright', 'icp_filing_number'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
            }

            $normalized[$field] = $field !== 'system_name' && $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }
}
