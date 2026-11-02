<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class LoginRequest extends EfficientFormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
