<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ListLoginLogsRequest extends FormRequest
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
            'guard' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:255'],
            'account' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'ip_address' => ['nullable', 'ip'],
            'successful' => ['nullable', 'boolean'],
            'created_at' => ['nullable', 'array', 'list', 'size:2'],
            'created_at.*' => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['successful'] as $field) {
            $value = $this->query($field);

            if ($value === 'true' || $value === 'false') {
                $this->merge([$field => $value === 'true']);
            }
        }
    }
}
