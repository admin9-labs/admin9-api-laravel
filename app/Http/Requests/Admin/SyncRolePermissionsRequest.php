<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use Illuminate\Validation\Rule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class SyncRolePermissionsRequest extends EfficientFormRequest
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
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists(Permission::class, 'name')->where('guard_name', 'admin')],
        ];
    }
}
