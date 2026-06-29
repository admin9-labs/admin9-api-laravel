<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
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
        $permission = $this->route('permission');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:125', 'regex:/^[a-z][a-z0-9_\.-]*(\.[a-z][a-z0-9_\.-]*)+$/', Rule::unique(Permission::class, 'name')->where('guard_name', 'admin')->ignore($permission)],
            'display_name' => ['nullable', 'string', 'max:125'],
            'group' => ['nullable', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
