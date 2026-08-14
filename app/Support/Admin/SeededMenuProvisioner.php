<?php

namespace App\Support\Admin;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SeededMenuProvisioner
{
    /**
     * Create missing menu definitions without reconciling developer edits.
     *
     * @param  array<int, array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}>  $definitions
     * @return array<int, string>
     */
    public function provision(array $definitions): array
    {
        return DB::transaction(function () use ($definitions): array {
            $warnings = [];
            $unavailableSeedKeys = [];

            foreach ($definitions as $definition) {
                $seedKey = $definition['seed_key'];
                $parentSeedKey = $definition['parent_seed_key'];

                $existingMenu = Menu::query()
                    ->where('seed_key', $seedKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingMenu instanceof Menu) {
                    continue;
                }

                if ($this->isTombstoned($seedKey)) {
                    $unavailableSeedKeys[$seedKey] = true;
                    $warnings[] = "Skipping seeded menu [{$seedKey}] because it was deleted by an administrator.";

                    continue;
                }

                if ($parentSeedKey !== null && isset($unavailableSeedKeys[$parentSeedKey])) {
                    $unavailableSeedKeys[$seedKey] = true;
                    $warnings[] = "Skipping seeded menu [{$seedKey}] because parent [{$parentSeedKey}] is unavailable.";

                    continue;
                }

                $parent = $this->resolveParent($seedKey, $parentSeedKey);

                if ($parentSeedKey !== null && ! $parent instanceof Menu) {
                    $unavailableSeedKeys[$seedKey] = true;
                    $warnings[] = "Skipping seeded menu [{$seedKey}] because parent [{$parentSeedKey}] is unavailable.";

                    continue;
                }

                $codeOwner = Menu::query()
                    ->where('code', $definition['code'])
                    ->lockForUpdate()
                    ->first();

                if ($codeOwner instanceof Menu) {
                    throw new LogicException(sprintf(
                        'Cannot seed menu [%s]: default code [%s] is already used by menu id [%d].',
                        $seedKey,
                        $definition['code'],
                        $codeOwner->getKey(),
                    ));
                }

                $permissions = $this->resolvePermissions($seedKey, $definition['permission_names']);
                $this->assertValidParentType($definition['type'], $parent, $seedKey);

                $menu = new Menu([
                    'parent_id' => $parent?->getKey(),
                    'name' => $definition['name'],
                    'code' => $definition['code'],
                    'path' => $definition['path'],
                    'component' => $definition['component'],
                    'icon' => $definition['icon'],
                    'type' => $definition['type'],
                    'sort' => $definition['sort'],
                    'is_visible' => $definition['is_visible'],
                    'is_active' => $definition['is_active'],
                ]);
                $menu->setAttribute('seed_key', $seedKey);
                $menu->save();
                $menu->permissions()->attach($permissions->modelKeys());
            }

            return $warnings;
        }, 5);
    }

    private function isTombstoned(string $seedKey): bool
    {
        return DB::table('menu_seed_tombstones')
            ->where('seed_key', $seedKey)
            ->lockForUpdate()
            ->exists();
    }

    private function resolveParent(string $seedKey, ?string $parentSeedKey): ?Menu
    {
        if ($parentSeedKey === null) {
            return null;
        }

        $parent = Menu::query()
            ->where('seed_key', $parentSeedKey)
            ->lockForUpdate()
            ->first();

        if ($parent instanceof Menu) {
            return $parent;
        }

        if ($this->isTombstoned($parentSeedKey)) {
            return null;
        }

        throw new LogicException(
            "Cannot seed menu [{$seedKey}]: required parent [{$parentSeedKey}] is missing."
        );
    }

    /**
     * @param  array<int, string>  $permissionNames
     * @return Collection<int, Permission>
     */
    private function resolvePermissions(string $seedKey, array $permissionNames): Collection
    {
        $permissions = Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', $permissionNames)
            ->get();
        $missingPermissionNames = collect($permissionNames)->diff($permissions->pluck('name'));

        if ($missingPermissionNames->isNotEmpty()) {
            throw new LogicException(sprintf(
                'Cannot seed menu [%s]: required admin permission(s) [%s] are missing.',
                $seedKey,
                $missingPermissionNames->implode(', '),
            ));
        }

        return $permissions;
    }

    private function assertValidParentType(string $type, ?Menu $parent, string $seedKey): void
    {
        $valid = match ($type) {
            Menu::TYPE_DIRECTORY => $parent === null,
            Menu::TYPE_PAGE => $parent?->type === Menu::TYPE_DIRECTORY,
            Menu::TYPE_BUTTON => $parent?->type === Menu::TYPE_PAGE,
            default => false,
        };

        if (! $valid) {
            throw new LogicException("Cannot seed menu [{$seedKey}]: its parent does not match the required menu hierarchy.");
        }
    }
}
