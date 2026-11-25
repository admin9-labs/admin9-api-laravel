<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;
use Spatie\Permission\Models\Role;

class SyncUserRolesRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::exists(Role::class, 'name')->where('guard_name', 'admin')],
        ];
    }
}
