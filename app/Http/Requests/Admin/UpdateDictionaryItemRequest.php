<?php

namespace App\Http\Requests\Admin;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDictionaryItemRequest extends FormRequest
{
    private const UNIQUE_CODE_MESSAGE = 'The code has already been taken for the selected dictionary type.';

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
        $dictionaryItem = $this->route('dictionary_item');
        $dictionaryTypeId = $this->input(
            'dictionary_type_id',
            $dictionaryItem instanceof DictionaryItem ? $dictionaryItem->dictionary_type_id : null
        );

        return [
            'dictionary_type_id' => ['sometimes', 'required', 'integer', Rule::exists(DictionaryType::class, 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_\.\-]*$/', Rule::unique(DictionaryItem::class, 'code')->where('dictionary_type_id', $dictionaryTypeId)->ignore($dictionaryItem)],
            'value' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'meta' => ['nullable', 'array'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['dictionary_type_id', 'code'])) {
                    return;
                }

                $dictionaryItem = $this->route('dictionary_item');

                if (! $dictionaryItem instanceof DictionaryItem) {
                    return;
                }

                $dictionaryTypeId = (int) $this->input('dictionary_type_id', $dictionaryItem->dictionary_type_id);
                $code = (string) $this->input('code', $dictionaryItem->code);

                $duplicateExists = DictionaryItem::query()
                    ->where('dictionary_type_id', $dictionaryTypeId)
                    ->where('code', $code)
                    ->whereKeyNot($dictionaryItem->getKey())
                    ->exists();

                if ($duplicateExists) {
                    $validator->errors()->add('code', self::UNIQUE_CODE_MESSAGE);
                }
            },
        ];
    }
}
