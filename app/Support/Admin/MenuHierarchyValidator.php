<?php

namespace App\Support\Admin;

use App\Models\Menu;

final class MenuHierarchyValidator
{
    public const DESCENDANT_PARENT_MESSAGE = 'The selected parent menu must not be a descendant of this menu.';

    /**
     * @param  array<int, array{id: int, parent_id: ?int, type: string}>  $menusById
     * @return array<string, array<int, string>>
     */
    public function errors(array $menusById, ?int $menuId, string $type, ?int $parentId): array
    {
        $errors = [];
        $parent = $parentId === null ? null : ($menusById[$parentId] ?? null);

        if ($type === Menu::TYPE_DIRECTORY && $parentId !== null) {
            $errors['parent_id'][] = 'Directory menus must be root menus.';
        }

        if ($type === Menu::TYPE_PAGE && ($parent === null || $parent['type'] !== Menu::TYPE_DIRECTORY)) {
            $errors['parent_id'][] = 'Page menus must belong to a directory menu.';
        }

        if ($type === Menu::TYPE_BUTTON && ($parent === null || $parent['type'] !== Menu::TYPE_PAGE)) {
            $errors['parent_id'][] = 'Button menus must belong to a page menu.';
        }

        if ($menuId === null) {
            return $errors;
        }

        $expectedChildType = match ($type) {
            Menu::TYPE_DIRECTORY => Menu::TYPE_PAGE,
            Menu::TYPE_PAGE => Menu::TYPE_BUTTON,
            Menu::TYPE_BUTTON => null,
            default => null,
        };

        foreach ($menusById as $menu) {
            if ($menu['parent_id'] !== $menuId) {
                continue;
            }

            if ($expectedChildType === null || $menu['type'] !== $expectedChildType) {
                $errors['type'][] = 'The selected menu type is incompatible with its existing child menus.';
                break;
            }
        }

        if ($parentId !== null && $this->isDescendant($menusById, $menuId, $parentId)) {
            $errors['parent_id'][] = self::DESCENDANT_PARENT_MESSAGE;
        }

        return $errors;
    }

    /**
     * @param  array<int, array{id: int, parent_id: ?int, type: string}>  $menusById
     */
    private function isDescendant(array $menusById, int $menuId, int $candidateParentId): bool
    {
        $visited = [];
        $currentId = $candidateParentId;

        while (true) {
            if ($currentId === $menuId || isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $parentId = $menusById[$currentId]['parent_id'] ?? null;

            if ($parentId === null) {
                return false;
            }

            $currentId = $parentId;
        }
    }
}
