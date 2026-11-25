<?php

namespace App\Http\Requests\Admin;

use Closure;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;
use Mitoop\LaravelQueryBuilder\ValueResolvers\Like;

class ListMembersRequest extends EfficientFormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (new Like)->resolve($value) === '') {
                        $fail('The '.$attribute.' field must contain a searchable character.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $isActive = $this->query('is_active');

        if ($isActive === 'true' || $isActive === 'false') {
            $this->merge(['is_active' => $isActive === 'true']);
        }
    }
}
