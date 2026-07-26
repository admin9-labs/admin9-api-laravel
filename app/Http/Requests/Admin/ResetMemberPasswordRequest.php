<?php

namespace App\Http\Requests\Admin;

use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResetMemberPasswordRequest extends FormRequest
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
            'password' => PasswordPolicy::rules(confirmed: true),
        ];
    }
}
