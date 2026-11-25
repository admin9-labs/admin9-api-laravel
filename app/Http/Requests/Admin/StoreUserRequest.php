<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Security\PasswordPolicy;
use Illuminate\Validation\Rule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class StoreUserRequest extends EfficientFormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => PasswordPolicy::rules(),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
