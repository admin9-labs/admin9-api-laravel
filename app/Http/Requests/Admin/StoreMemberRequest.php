<?php

namespace App\Http\Requests\Admin;

use App\Models\Member;
use App\Rules\NotEmailAddress;
use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
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
            'email' => [
                'nullable',
                'required_without:mobile',
                'email',
                'max:255',
                Rule::unique(Member::class, 'email'),
                Rule::unique(Member::class, 'mobile'),
            ],
            'mobile' => [
                'nullable',
                'required_without:email',
                'string',
                'max:32',
                new NotEmailAddress,
                Rule::unique(Member::class, 'mobile'),
                Rule::unique(Member::class, 'email'),
            ],
            'password' => PasswordPolicy::rules(confirmed: true),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizedIdentityFields());
    }

    /**
     * @return array<string, string|null>
     */
    private function normalizedIdentityFields(): array
    {
        return collect(['email', 'mobile'])
            ->filter(fn (string $field): bool => $this->exists($field))
            ->mapWithKeys(function (string $field): array {
                $value = $this->input($field);
                $value = is_string($value) ? trim($value) : $value;

                return [$field => $value === '' ? null : $value];
            })
            ->all();
    }
}
