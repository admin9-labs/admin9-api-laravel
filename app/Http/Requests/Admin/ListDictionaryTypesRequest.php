<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ListDictionaryTypesRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['is_active'] as $field) {
            $value = $this->query($field);

            if ($value === 'true' || $value === 'false') {
                $this->merge([$field => $value === 'true']);
            }
        }
    }
}
