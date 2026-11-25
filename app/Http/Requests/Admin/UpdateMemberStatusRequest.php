<?php

namespace App\Http\Requests\Admin;

use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class UpdateMemberStatusRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
