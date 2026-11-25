<?php

namespace App\Http\Requests\Admin;

use App\Support\Security\PasswordPolicy;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class ResetMemberPasswordRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => PasswordPolicy::rules(confirmed: true),
        ];
    }
}
