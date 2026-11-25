<?php

namespace App\Http\Requests\Admin;

use App\Support\Security\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class ResetUserPasswordRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => PasswordPolicy::rules(confirmed: true),
        ];
    }
}
