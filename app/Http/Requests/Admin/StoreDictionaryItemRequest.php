<?php

namespace App\Http\Requests\Admin;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDictionaryItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'dictionary_type_id' => ['required', 'integer', Rule::exists(DictionaryType::class, 'id')],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_\.\-]*$/', Rule::unique(DictionaryItem::class, 'code')->where('dictionary_type_id', $this->input('dictionary_type_id'))],
            'value' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'meta' => ['nullable', 'array'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
