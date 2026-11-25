<?php

namespace App\Http\Requests\Admin;

use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class DeleteFileByUrlRequest extends EfficientFormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['url' => ['required', 'string', 'max:2048']];
    }
}
