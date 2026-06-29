<?php

namespace Tests\Unit;

use App\Models\Member;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\AdminPermissionChecker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPermissionCheckerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_non_user_authenticatable_subject_cannot_access_admin_permission(): void
    {
        $checker = new AdminPermissionChecker;

        $this->assertFalse($checker->canAccess(Member::factory()->create(), 'system.menu.view'));
    }

    public function test_user_with_assigned_permission_can_access_permission(): void
    {
        $checker = new AdminPermissionChecker;
        $grantedPermission = Permission::findOrCreate('system.menu.view', 'admin');
        Permission::findOrCreate('system.menu.update', 'admin');
        $user = User::factory()->create();

        $user->givePermissionTo($grantedPermission);

        $this->assertTrue($checker->canAccess($user, 'system.menu.view'));
        $this->assertFalse($checker->canAccess($user, 'system.menu.update'));
    }

    public function test_user_with_assigned_permission_can_access_loaded_permission(): void
    {
        $checker = new AdminPermissionChecker;
        $grantedPermission = Permission::findOrCreate('system.menu.loaded', 'admin');
        $deniedPermission = Permission::findOrCreate('system.menu.loaded-denied', 'admin');
        $user = User::factory()->create();

        $user->givePermissionTo($grantedPermission);

        $this->assertTrue($checker->canAccessPermission($user, $grantedPermission));
        $this->assertFalse($checker->canAccessPermission($user, $deniedPermission));
        $this->assertFalse($checker->canAccessPermission($user, null));
    }

    public function test_super_admin_bypasses_direct_permission_assignment(): void
    {
        $checker = new AdminPermissionChecker;
        Permission::findOrCreate('system.menu.delete', 'admin');
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $this->assertTrue($checker->canAccess($user, 'system.menu.delete'));
    }

    public function test_super_admin_cannot_access_inactive_or_unknown_permission(): void
    {
        $checker = new AdminPermissionChecker;
        $permission = Permission::findOrCreate('system.menu.archive', 'admin');
        $permission->forceFill(['is_active' => false])->save();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $this->assertFalse($checker->canAccess($user, 'system.menu.archive'));
        $this->assertFalse($checker->canAccess($user, 'system.menu.unknown'));
    }

    public function test_super_admin_cannot_access_inactive_or_non_admin_loaded_permission(): void
    {
        $checker = new AdminPermissionChecker;
        $inactivePermission = Permission::findOrCreate('system.menu.loaded-inactive', 'admin');
        $inactivePermission->forceFill(['is_active' => false])->save();
        $webPermission = Permission::findOrCreate('system.menu.web', 'web');

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $this->assertFalse($checker->canAccessPermission($user, $inactivePermission->refresh()));
        $this->assertFalse($checker->canAccessPermission($user, $webPermission));
    }
}
