<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateFileByUrlRequest extends UpdateFileRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [...parent::rules(), 'url' => ['required', 'string', 'max:2048']];
    }
}
