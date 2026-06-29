<?php

namespace App\Support\Admin;

use App\Models\User;
use Spatie\Permission\Models\Role;

class ReservedAdminRole
{
    public const SUPER_ADMIN = 'super-admin';

    public const SYSTEM_ADMIN = 'system-admin';

    private const ADMIN_GUARD = 'admin';

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [
            self::SUPER_ADMIN,
            self::SYSTEM_ADMIN,
        ];
    }

    public static function isReserved(Role $role): bool
    {
        return $role->guard_name === self::ADMIN_GUARD
            && in_array($role->name, self::names(), true);
    }

    public static function userIsSuperAdmin(User $user): bool
    {
        return $user->hasRole(self::SUPER_ADMIN, self::ADMIN_GUARD);
    }

    public static function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->role(self::SUPER_ADMIN, self::ADMIN_GUARD)
            ->count();
    }
}
