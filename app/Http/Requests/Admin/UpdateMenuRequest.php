<?php

namespace App\Http\Requests\Admin;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMenuRequest extends FormRequest
{
    private const DESCENDANT_PARENT_MESSAGE = 'The selected parent menu must not be a descendant of this menu.';

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
        $menu = $this->route('menu');

        return [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists(Menu::class, 'id'),
                Rule::notIn([$menu instanceof Menu ? $menu->id : null]),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique(Menu::class, 'code')->ignore($menu)],
            'path' => ['nullable', 'string', 'max:255'],
            'component' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(Menu::allowedTypes())],
            'permission_id' => ['nullable', 'integer', Rule::exists(Permission::class, 'id')->where('guard_name', 'admin')],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('parent_id') || ! $this->filled('parent_id')) {
                    return;
                }

                $menu = $this->route('menu');

                if (! $menu instanceof Menu) {
                    return;
                }

                if ($this->isDescendantMenu((int) $this->input('parent_id'), $menu->id)) {
                    $validator->errors()->add('parent_id', self::DESCENDANT_PARENT_MESSAGE);
                }
            },
        ];
    }

    private function isDescendantMenu(int $candidateParentId, int $menuId): bool
    {
        $parentId = Menu::query()->whereKey($candidateParentId)->value('parent_id');

        while ($parentId !== null) {
            if ((int) $parentId === $menuId) {
                return true;
            }

            $parentId = Menu::query()->whereKey($parentId)->value('parent_id');
        }

        return false;
    }
}
