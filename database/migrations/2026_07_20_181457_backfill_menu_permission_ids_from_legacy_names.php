<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $permissions = $connection->table('permissions')
                ->select(['id', 'name', 'guard_name'])
                ->lockForUpdate()
                ->get();
            $permissionsById = $permissions->keyBy(fn (object $permission): int => (int) $permission->id);
            $adminPermissionsByName = $permissions
                ->where('guard_name', 'admin')
                ->keyBy('name');
            $permissionGuardsByName = $permissions
                ->groupBy('name')
                ->map(fn ($matchingPermissions): string => $matchingPermissions
                    ->pluck('guard_name')
                    ->unique()
                    ->sort()
                    ->implode(', '));
            $menus = $connection->table('menus')
                ->select(['id', 'code', 'permission_id', 'permission_name'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $errors = [];
            $backfills = [];

            foreach ($menus as $menu) {
                $menuIdentifier = sprintf('menu [id=%d, code=%s]', $menu->id, $menu->code);
                $permissionId = $menu->permission_id === null ? null : (int) $menu->permission_id;
                $legacyPermissionName = $menu->permission_name === null ? null : (string) $menu->permission_name;

                if ($permissionId !== null) {
                    $permission = $permissionsById->get($permissionId);

                    if ($permission === null) {
                        $errors[] = sprintf('%s references missing permission_id [%d].', $menuIdentifier, $permissionId);

                        continue;
                    }

                    if ($permission->guard_name !== 'admin') {
                        $errors[] = sprintf(
                            '%s references permission_id [%d] with non-admin guard [%s].',
                            $menuIdentifier,
                            $permissionId,
                            $permission->guard_name,
                        );

                        continue;
                    }

                    if ($legacyPermissionName !== null && $legacyPermissionName !== $permission->name) {
                        $errors[] = sprintf(
                            '%s has conflicting permission_id [%d] name [%s] and legacy permission_name [%s].',
                            $menuIdentifier,
                            $permissionId,
                            $permission->name,
                            $legacyPermissionName,
                        );
                    }

                    continue;
                }

                if ($legacyPermissionName === null) {
                    continue;
                }

                $permission = $adminPermissionsByName->get($legacyPermissionName);

                if ($permission === null) {
                    $matchingGuards = $permissionGuardsByName->get($legacyPermissionName);
                    $reason = $matchingGuards === null
                        ? 'does not resolve to an admin permission'
                        : sprintf('matches only non-admin guard(s) [%s]', $matchingGuards);
                    $errors[] = sprintf(
                        '%s legacy permission_name [%s] %s.',
                        $menuIdentifier,
                        $legacyPermissionName,
                        $reason,
                    );

                    continue;
                }

                $backfills[(int) $menu->id] = (int) $permission->id;
            }

            $this->throwIfInvalid($errors);

            foreach ($backfills as $menuId => $permissionId) {
                $updatedRows = $connection->table('menus')
                    ->where('id', $menuId)
                    ->whereNull('permission_id')
                    ->update(['permission_id' => $permissionId]);

                if ($updatedRows !== 1) {
                    throw new RuntimeException(sprintf(
                        'Menu [id=%d] changed while permission IDs were being backfilled.',
                        $menuId,
                    ));
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $permissionsById = $connection->table('permissions')
                ->select(['id', 'name', 'guard_name'])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (object $permission): int => (int) $permission->id);
            $menus = $connection->table('menus')
                ->select(['id', 'code', 'permission_id'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $errors = [];
            $permissionNames = [];

            foreach ($menus as $menu) {
                if ($menu->permission_id === null) {
                    $permissionNames[(int) $menu->id] = null;

                    continue;
                }

                $permissionId = (int) $menu->permission_id;
                $permission = $permissionsById->get($permissionId);

                if ($permission === null || $permission->guard_name !== 'admin') {
                    $errors[] = sprintf(
                        'menu [id=%d, code=%s] cannot restore permission_name from permission_id [%d].',
                        $menu->id,
                        $menu->code,
                        $permissionId,
                    );

                    continue;
                }

                $permissionNames[(int) $menu->id] = (string) $permission->name;
            }

            $this->throwIfInvalid($errors);

            foreach ($permissionNames as $menuId => $permissionName) {
                $connection->table('menus')
                    ->where('id', $menuId)
                    ->update(['permission_name' => $permissionName]);
            }
        });
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        throw new RuntimeException("Cannot canonicalize menu permissions:\n- ".implode("\n- ", $errors));
    }
};
