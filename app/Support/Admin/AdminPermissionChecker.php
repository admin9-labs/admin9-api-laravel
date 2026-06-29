<?php

namespace App\Support\Admin;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

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
}
