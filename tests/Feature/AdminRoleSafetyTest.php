<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Audit\AdminActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminRoleSafetyTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_reserved_roles_cannot_be_updated_deleted_or_permission_synced_by_role_manager(): void
    {
        $this->seedRoleManagementPermissions();
        $role = Role::findOrCreate('super-admin', 'admin');
        $token = $this->managerTokenFor([
            'system.role.update',
            'system.role.delete',
        ]);

        $this->patchJson('/api/admin/roles/'.$role->id, [
            'name' => 'renamed-super-admin',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->putJson('/api/admin/roles/'.$role->id.'/permissions', [
            'permissions' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->deleteJson('/api/admin/roles/'.$role->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'super-admin',
            'guard_name' => 'admin',
        ]);
    }

    public function test_ordinary_admin_roles_remain_manageable(): void
    {
        $this->seedRoleManagementPermissions();
        $role = Role::findOrCreate('operator', 'admin');
        $permission = $this->createPermission('dynamic.operator.view');
        $token = $this->managerTokenFor([
            'system.role.update',
            'system.role.delete',
        ]);

        $this->patchJson('/api/admin/roles/'.$role->id, [
            'name' => 'operator-renamed',
            'permissions' => [$permission->name],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role.name', 'operator-renamed');

        $this->putJson('/api/admin/roles/'.$role->id.'/permissions', [
            'permissions' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->deleteJson('/api/admin/roles/'.$role->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_ordinary_role_cannot_be_renamed_to_reserved_role_name(): void
    {
        $this->seedRoleManagementPermissions();
        $role = Role::findOrCreate('operator', 'admin');
        Role::query()->where('name', 'system-admin')->where('guard_name', 'admin')->delete();
        $token = $this->managerTokenFor(['system.role.update']);

        $this->patchJson('/api/admin/roles/'.$role->id, [
            'name' => 'system-admin',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertSame('operator', $role->refresh()->name);
    }

    public function test_reserved_role_names_cannot_be_created(): void
    {
        $this->createPermission('system.role.create', ['is_system' => true]);
        $token = $this->managerTokenFor(['system.role.create']);

        $this->postJson('/api/admin/roles', [
            'name' => 'system-admin',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);
    }

    public function test_sync_endpoints_reject_member_guard_permissions_and_roles(): void
    {
        $this->seedRoleManagementPermissions();
        $this->seedUserRoleManagementPermission();

        $role = Role::findOrCreate('operator', 'admin');
        Permission::findOrCreate('member.only.permission', 'member');
        Role::findOrCreate('member-only-role', 'member');
        $target = User::factory()->create(['email' => 'member-guard-role-target@example.com']);
        $token = $this->managerTokenFor([
            'system.role.update',
            'system.user.assign-role',
        ]);

        $this->putJson('/api/admin/roles/'.$role->id.'/permissions', [
            'permissions' => ['member.only.permission'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->putJson('/api/admin/users/'.$target->id.'/roles', [
            'roles' => ['member-only-role'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);
    }

    public function test_non_super_admin_cannot_grant_or_remove_super_admin_role(): void
    {
        $this->seedUserRoleManagementPermission();
        $target = User::factory()->create(['email' => 'target-role-safety@example.com']);
        $currentSuperAdmin = $this->createSuperAdmin('existing-super-role-safety@example.com');
        $token = $this->managerTokenFor(['system.user.assign-role']);

        $this->putJson('/api/admin/users/'.$target->id.'/roles', [
            'roles' => ['super-admin'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->putJson('/api/admin/users/'.$currentSuperAdmin->id.'/roles', [
            'roles' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertFalse($target->refresh()->hasRole('super-admin'));
        $this->assertTrue($currentSuperAdmin->refresh()->hasRole('super-admin'));
    }

    public function test_super_admin_can_grant_super_admin_role_when_using_assignment_permission(): void
    {
        $this->seedUserRoleManagementPermission();
        $target = User::factory()->create(['email' => 'target-super-grant@example.com']);
        $actor = $this->createSuperAdmin('actor-super-grant@example.com');
        $token = $this->adminTokenFor($actor);

        $this->putJson('/api/admin/users/'.$target->id.'/roles', [
            'roles' => ['super-admin'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($target->refresh()->hasRole('super-admin'));
    }

    public function test_last_active_super_admin_role_cannot_be_removed(): void
    {
        $this->seedUserRoleManagementPermission();
        $superAdmin = $this->createSuperAdmin('last-super-role@example.com');
        $token = $this->adminTokenFor($superAdmin);

        $this->putJson('/api/admin/users/'.$superAdmin->id.'/roles', [
            'roles' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertTrue($superAdmin->refresh()->hasRole('super-admin'));
    }

    public function test_inactive_super_admin_role_can_be_removed_when_one_active_super_admin_remains(): void
    {
        $this->seedUserRoleManagementPermission();
        $activeSuperAdmin = $this->createSuperAdmin('active-super-role@example.com');
        $inactiveSuperAdmin = $this->createSuperAdmin('inactive-super-role@example.com');
        $inactiveSuperAdmin->update(['is_active' => false]);
        $token = $this->adminTokenFor($activeSuperAdmin);

        $this->putJson('/api/admin/users/'.$inactiveSuperAdmin->id.'/roles', [
            'roles' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($inactiveSuperAdmin->refresh()->hasRole('super-admin'));
        $this->assertTrue($activeSuperAdmin->refresh()->hasRole('super-admin'));
    }

    public function test_role_creation_rolls_back_when_activity_recording_fails(): void
    {
        $this->createPermission('system.role.create', ['is_system' => true]);
        $permission = $this->createPermission('dynamic.rollback.create');
        $token = $this->managerTokenFor(['system.role.create']);
        $this->bindFailingActivityRecorder();

        $this->assertAuditFailure(function () use ($permission, $token): void {
            $this->postJson('/api/admin/roles', [
                'name' => 'rollback-created-role',
                'permissions' => [$permission->name],
            ], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertDatabaseMissing('roles', [
            'name' => 'rollback-created-role',
            'guard_name' => 'admin',
        ]);
    }

    public function test_role_update_rolls_back_when_activity_recording_fails(): void
    {
        $this->seedRoleManagementPermissions();
        $role = Role::findOrCreate('rollback-updated-role', 'admin');
        $oldPermission = $this->createPermission('dynamic.rollback.update.old');
        $newPermission = $this->createPermission('dynamic.rollback.update.new');
        $role->givePermissionTo($oldPermission);
        $token = $this->managerTokenFor(['system.role.update']);
        $this->bindFailingActivityRecorder();

        $this->assertAuditFailure(function () use ($newPermission, $role, $token): void {
            $this->patchJson('/api/admin/roles/'.$role->id, [
                'name' => 'rollback-updated-role-renamed',
                'permissions' => [$newPermission->name],
            ], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertSame('rollback-updated-role', $role->refresh()->name);
        $this->assertSame([$oldPermission->name], $this->rolePermissionNames($role));
    }

    public function test_role_permission_sync_rolls_back_when_activity_recording_fails(): void
    {
        $this->seedRoleManagementPermissions();
        $role = Role::findOrCreate('rollback-synced-role', 'admin');
        $oldPermission = $this->createPermission('dynamic.rollback.sync.old');
        $newPermission = $this->createPermission('dynamic.rollback.sync.new');
        $role->givePermissionTo($oldPermission);
        $token = $this->managerTokenFor(['system.role.update']);
        $this->bindFailingActivityRecorder();

        $this->assertAuditFailure(function () use ($newPermission, $role, $token): void {
            $this->putJson('/api/admin/roles/'.$role->id.'/permissions', [
                'permissions' => [$newPermission->name],
            ], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertSame([$oldPermission->name], $this->rolePermissionNames($role));
    }

    public function test_role_deletion_rolls_back_when_activity_recording_fails(): void
    {
        $this->seedRoleManagementPermissions();
        $role = Role::findOrCreate('rollback-deleted-role', 'admin');
        $token = $this->managerTokenFor(['system.role.delete']);
        $this->bindFailingActivityRecorder();

        $this->assertAuditFailure(function () use ($role, $token): void {
            $this->deleteJson('/api/admin/roles/'.$role->id, [], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'rollback-deleted-role',
            'guard_name' => 'admin',
        ]);
    }

    public function test_user_role_sync_rolls_back_when_activity_recording_fails(): void
    {
        $this->seedUserRoleManagementPermission();
        $oldRole = Role::findOrCreate('rollback-user-role-old', 'admin');
        $newRole = Role::findOrCreate('rollback-user-role-new', 'admin');
        $target = User::factory()->create(['email' => 'rollback-user-role@example.com']);
        $target->assignRole($oldRole);
        $token = $this->managerTokenFor(['system.user.assign-role']);
        $this->bindFailingActivityRecorder();

        $this->assertAuditFailure(function () use ($newRole, $target, $token): void {
            $this->putJson('/api/admin/users/'.$target->id.'/roles', [
                'roles' => [$newRole->name],
            ], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertSame([$oldRole->name], $this->userRoleNames($target));
    }

    private function seedRoleManagementPermissions(): void
    {
        $this->createPermission('system.role.update', ['is_system' => true]);
        $this->createPermission('system.role.delete', ['is_system' => true]);
    }

    private function seedUserRoleManagementPermission(): void
    {
        $this->createPermission('system.user.assign-role', ['is_system' => true]);
    }

    private function bindFailingActivityRecorder(): void
    {
        $this->app->instance(AdminActivityRecorder::class, new class extends AdminActivityRecorder
        {
            public function record(Model $subject, string $event, array $properties = []): ?Activity
            {
                throw new RuntimeException('Audit recorder failed');
            }
        });
    }

    private function assertAuditFailure(callable $request): void
    {
        $this->withoutExceptionHandling();

        try {
            $request();
            $this->fail('Expected audit recorder failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit recorder failed', $exception->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    private function rolePermissionNames(Role $role): array
    {
        return $role->refresh()
            ->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function userRoleNames(User $user): array
    {
        return $user->refresh()
            ->roles()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }
}
