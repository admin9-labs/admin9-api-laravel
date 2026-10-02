<?php

namespace App\Http\Requests\Admin;

use App\Models\FileDirectory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'directory_id' => ['present', 'nullable', 'integer', Rule::exists(FileDirectory::class, 'id')],
        ];
    }
}
