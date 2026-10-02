<?php

namespace App\Http\Requests\Admin;

use App\Models\FileDirectory;
use App\Support\FileUploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFileRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'prohibits:types', Rule::in(app(FileUploadPolicy::class)->types())],
            'types' => ['sometimes', 'array', 'max:5', 'prohibits:type'],
            'types.*' => ['required', Rule::in(app(FileUploadPolicy::class)->types())],
            'ungrouped' => ['sometimes', 'boolean', 'prohibits:directory_id'],
            'directory_id' => ['sometimes', 'integer', Rule::exists(FileDirectory::class, 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->query('ungrouped');
        if ($value === 'true' || $value === 'false') {
            $this->merge(['ungrouped' => $value === 'true']);
        }
    }
}
