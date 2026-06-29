<?php

namespace App\Http\Requests\Admin;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuRequest extends FormRequest
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
            'parent_id' => ['nullable', 'integer', Rule::exists(Menu::class, 'id')],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', Rule::unique(Menu::class, 'code')],
            'path' => ['nullable', 'string', 'max:255'],
            'component' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(Menu::allowedTypes())],
            'permission_name' => ['nullable', 'string', 'max:125'],
            'permission_id' => ['nullable', 'integer', Rule::exists(Permission::class, 'id')->where('guard_name', 'admin')],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
