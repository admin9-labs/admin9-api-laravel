<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ListSystemConfigsRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer'],
            'sorts' => ['nullable', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:150'],
            'name' => ['nullable', 'string', 'max:100'],
            'config_group' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:30'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'is_public' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['is_public', 'is_active'] as $field) {
            $value = $this->query($field);

            if ($value === 'true' || $value === 'false') {
                $this->merge([$field => $value === 'true']);
            }
        }
    }
}
