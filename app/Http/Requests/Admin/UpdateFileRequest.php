<?php

namespace App\Http\Requests\Admin;

use App\Models\FileDirectory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class UpdateFileRequest extends EfficientFormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'directory_id' => ['present', 'nullable', 'integer', Rule::exists(FileDirectory::class, 'id')],
        ];
    }
}
