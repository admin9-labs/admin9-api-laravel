<?php

namespace App\Http\Requests\Admin;

use App\Support\Admin\ReservedAdminRole;
use Illuminate\Validation\Rule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends EfficientFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::notIn(ReservedAdminRole::names()), Rule::unique(Role::class, 'name')->where('guard_name', 'admin')->ignore($this->route('role'))],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists(Permission::class, 'name')->where('guard_name', 'admin')],
        ];
    }
}
