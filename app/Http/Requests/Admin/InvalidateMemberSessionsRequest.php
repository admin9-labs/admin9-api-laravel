<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Validator;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class InvalidateMemberSessionsRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->getContent() !== '') {
                    $validator->errors()->add('body', 'This endpoint does not accept a request body.');
                }
            },
        ];
    }
}
