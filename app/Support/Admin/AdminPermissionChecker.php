<?php

namespace App\Support\Admin;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class AdminPermissionChecker
{
    public function canAccess(Authenticatable $user, string $permissionName): bool
    {
        $permission = Permission::query()
            ->where('name', $permissionName)
            ->where('guard_name', 'admin')
            ->first(['id', 'name', 'guard_name', 'is_active']);

        return $this->canAccessPermission($user, $permission);
    }

    public function canAccessPermission(Authenticatable $user, ?Permission $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($permission === null || $permission->guard_name !== 'admin' || ! (bool) $permission->is_active) {
            return false;
        }

        if (ReservedAdminRole::userIsSuperAdmin($user)) {
            return true;
        }

        return $user->hasPermissionTo($permission);
    }

    /**
     * @param  iterable<int, Permission>  $permissions
     */
    public function canAccessAnyPermission(Authenticatable $user, iterable $permissions): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $activeAdminPermissions = Collection::make($permissions)
            ->filter(fn (Permission $permission): bool => $permission->guard_name === 'admin' && (bool) $permission->is_active);

        if ($activeAdminPermissions->isEmpty()) {
            return false;
        }

        if (ReservedAdminRole::userIsSuperAdmin($user)) {
            return true;
        }

        return $activeAdminPermissions->contains(
            fn (Permission $permission): bool => $user->hasPermissionTo($permission)
        );
    }
}
