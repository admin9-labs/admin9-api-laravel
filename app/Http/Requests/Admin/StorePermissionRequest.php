<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use Illuminate\Validation\Rule;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class StorePermissionRequest extends EfficientFormRequest
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
            'name' => ['required', 'string', 'max:125', 'regex:/^[a-z][a-z0-9_\.-]*(\.[a-z][a-z0-9_\.-]*)+$/', Rule::unique(Permission::class, 'name')->where('guard_name', 'admin')],
            'display_name' => ['nullable', 'string', 'max:125'],
            'group' => ['nullable', 'string', 'max:125'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
