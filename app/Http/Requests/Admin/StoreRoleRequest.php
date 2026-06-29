<?php

namespace App\Http\Requests\Admin;

use App\Support\Admin\ReservedAdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::notIn(ReservedAdminRole::names()), Rule::unique(Role::class, 'name')->where('guard_name', 'admin')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists(Permission::class, 'name')->where('guard_name', 'admin')],
        ];
    }
}
