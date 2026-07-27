<?php

namespace App\Http\Requests\Admin;

use App\Models\Member;
use App\Rules\NotEmailAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMemberRequest extends FormRequest
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
        $member = $this->route('member');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique(Member::class, 'email')->ignore($member),
                Rule::unique(Member::class, 'mobile')->ignore($member),
            ],
            'mobile' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                new NotEmailAddress,
                Rule::unique(Member::class, 'mobile')->ignore($member),
                Rule::unique(Member::class, 'email')->ignore($member),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $member = $this->route('member');

                if ($member instanceof Member) {
                    $email = $this->exists('email') ? $this->input('email') : $member->email;
                    $mobile = $this->exists('mobile') ? $this->input('mobile') : $member->mobile;

                    if ($email === null && $mobile === null) {
                        $validator->errors()->add('email', 'An email address or mobile number is required.');
                        $validator->errors()->add('mobile', 'An email address or mobile number is required.');
                    }
                }

                foreach (['password', 'password_confirmation', 'is_active', 'auth_version'] as $field) {
                    if ($this->exists($field)) {
                        $validator->errors()->add($field, 'Use the dedicated member operation endpoint.');
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = collect(['email', 'mobile'])
            ->filter(fn (string $field): bool => $this->exists($field))
            ->mapWithKeys(function (string $field): array {
                $value = $this->input($field);
                $value = is_string($value) ? trim($value) : $value;

                return [$field => $value === '' ? null : $value];
            })
            ->all();

        $this->merge($normalized);
    }
}
