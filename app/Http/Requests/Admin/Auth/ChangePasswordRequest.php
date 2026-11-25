<?php

namespace App\Http\Requests\Admin\Auth;

use App\Support\Security\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class ChangePasswordRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:admin'],
            'password' => PasswordPolicy::rules(confirmed: true),
        ];
    }
}
