<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ListActivityLogsRequest extends FormRequest
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
            'log_name' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:255'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'causer_id' => ['nullable', 'integer', 'min:1'],
            'created_at' => ['nullable', 'array', 'list', 'size:2'],
            'created_at.*' => ['required', 'date'],
        ];
    }
}
