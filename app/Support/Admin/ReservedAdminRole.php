<?php

namespace App\Support\Admin;

use App\Models\User;
use Illuminate\Support\Collection;
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

    public static function userHasReservedRole(User $user): bool
    {
        return $user->roles()
            ->where('guard_name', self::ADMIN_GUARD)
            ->whereIn('name', self::names())
            ->exists();
    }

    /**
     * @return Collection<int, int>
     */
    public static function activeSuperAdminIdsForUpdate(): Collection
    {
        $role = Role::query()
            ->where('name', self::SUPER_ADMIN)
            ->where('guard_name', self::ADMIN_GUARD)
            ->lockForUpdate()
            ->first(['id']);

        if (! $role instanceof Role) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    public static function lockUserForUpdate(User $user): User
    {
        return User::query()
            ->lockForUpdate()
            ->findOrFail($user->getKey());
    }

    /**
     * @param  Collection<int, int>  $activeSuperAdminIds
     */
    public static function isLastActiveSuperAdmin(User $user, Collection $activeSuperAdminIds): bool
    {
        return $activeSuperAdminIds->contains($user->getKey())
            && $activeSuperAdminIds->count() === 1;
    }
}
