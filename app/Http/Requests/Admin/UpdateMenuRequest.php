<?php

namespace App\Http\Requests\Admin;

use App\Models\Menu;
use App\Models\Permission;
use App\Support\Admin\MenuHierarchyValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMenuRequest extends FormRequest
{
    public const DESCENDANT_PARENT_MESSAGE = MenuHierarchyValidator::DESCENDANT_PARENT_MESSAGE;

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
            'icon' => ['nullable', 'string', 'max:100', 'regex:/^(?:icon-)?[a-z][a-z0-9-]*$/'],
            'type' => ['sometimes', 'string', Rule::in(Menu::allowedTypes())],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists(Permission::class, 'id')->where('guard_name', 'admin')],
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
                if ($this->exists('permission_id')) {
                    $validator->errors()->add('permission_id', 'The permission_id field is no longer supported. Use permission_ids instead.');
                }

                if ($this->exists('permission_name')) {
                    $validator->errors()->add('permission_name', 'The permission_name field is no longer supported. Use permission_ids instead.');
                }
            },
            function (Validator $validator): void {
                if (! $this->exists('parent_id') && ! $this->exists('type')) {
                    return;
                }

                if ($validator->errors()->hasAny(['parent_id', 'type'])) {
                    return;
                }

                $menu = $this->route('menu');

                if (! $menu instanceof Menu) {
                    return;
                }

                $parentId = $this->exists('parent_id') ? $this->input('parent_id') : $menu->parent_id;
                $errors = app(MenuHierarchyValidator::class)->errors(
                    $this->menuSnapshot(),
                    (int) $menu->getKey(),
                    (string) $this->input('type', $menu->type),
                    $parentId === null ? null : (int) $parentId,
                );

                foreach ($errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /**
     * @return array<int, array{id: int, parent_id: ?int, type: string}>
     */
    private function menuSnapshot(): array
    {
        return Menu::query()
            ->select(['id', 'parent_id', 'type'])
            ->get()
            ->mapWithKeys(static fn (Menu $menu): array => [
                (int) $menu->getKey() => [
                    'id' => (int) $menu->getKey(),
                    'parent_id' => $menu->parent_id === null ? null : (int) $menu->parent_id,
                    'type' => (string) $menu->type,
                ],
            ])
            ->all();
    }
}
