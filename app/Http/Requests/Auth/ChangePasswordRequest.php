<?php

namespace App\Http\Requests\Auth;

use App\Support\Security\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class ChangePasswordRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:member'],
            'password' => PasswordPolicy::rules(confirmed: true),
        ];
    }
}
