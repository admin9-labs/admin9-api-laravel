<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class UpdateUserRequest extends EfficientFormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->route('user'))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->exists('password')) {
                    $validator->errors()->add('password', 'Use the dedicated password reset endpoint.');
                }
            },
        ];
    }
}
