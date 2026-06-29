<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FailingActivity;
use Tests\TestCase;

class ActivityAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_menu_changes_are_audited_with_request_context_and_sensitive_fields_filtered(): void
    {
        $this->createPermission('system.menu.create');
        $this->createPermission('system.menu.update');

        $admin = User::factory()->create(['email' => 'audit-menu@example.com']);
        $admin->givePermissionTo(['system.menu.create', 'system.menu.update']);
        $token = $this->adminTokenFor($admin);

        $create = $this->postJson('/api/admin/menus', [
            'name' => '审计菜单',
            'code' => 'audit.menu',
            'path' => '/audit/menu',
            'permission_name' => 'system.menu.create',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $menuId = $create->json('data.menu.id');
        $this->assertIsInt($menuId);

        $this->patchJson('/api/admin/menus/'.$menuId, [
            'name' => '审计菜单更新',
            'permission_name' => 'system.menu.update',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $activity */
        $activity = Activity::query()->where('subject_type', (new Menu)->getMorphClass())->where('subject_id', $menuId)->latest('id')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame('admin', $activity->log_name);
        $this->assertSame('updated', $activity->event);
        $this->assertSame('admin', $properties['guard']);
        $this->assertSame('admin.menus.update', $properties['route']);
        $this->assertNotEmpty($properties['request_id']);
        $this->assertSame('127.0.0.1', $properties['ip_address']);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('审计菜单更新', $properties['attributes']['name']);

        $this->assertActivityPropertiesAreSanitized($activity);
    }

    public function test_admin_user_password_changes_are_not_stored_in_activity_properties(): void
    {
        $this->createPermission('system.user.create');
        $this->createPermission('system.user.update');

        $admin = User::factory()->create(['email' => 'audit-user-admin@example.com']);
        $admin->givePermissionTo(['system.user.create', 'system.user.update']);
        $token = $this->adminTokenFor($admin);

        $create = $this->postJson('/api/admin/users', [
            'name' => 'Audit User',
            'email' => 'audit-user@example.com',
            'password' => 'secret-password',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $userId = $create->json('data.user.id');
        $this->assertIsInt($userId);

        $this->patchJson('/api/admin/users/'.$userId, [
            'password' => 'new-secret-password',
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        /** @var Activity $activity */
        $activity = Activity::query()->where('subject_type', (new User)->getMorphClass())->where('subject_id', $userId)->latest('id')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame('updated', $activity->event);
        $this->assertFalse($properties['attributes']['is_active']);
        $this->assertArrayNotHasKey('password', $properties['attributes']);
        $this->assertArrayNotHasKey('password', $properties['old']);
        $this->assertActivityPropertiesAreSanitized($activity);
    }

    public function test_role_permission_sync_is_audited_without_token_values(): void
    {
        $this->createPermission('system.role.create');
        $this->createPermission('system.role.update');
        $this->createPermission('system.user.assign-role');
        Permission::findOrCreate('system.menu.view', 'admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::factory()->create(['email' => 'audit-role-admin@example.com']);
        $admin->givePermissionTo(['system.role.create', 'system.role.update', 'system.user.assign-role']);
        $token = $this->adminTokenFor($admin);

        $roleResponse = $this->postJson('/api/admin/roles', [
            'name' => 'auditor',
            'permissions' => ['system.menu.view'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $roleId = $roleResponse->json('data.role.id');
        $this->assertIsInt($roleId);

        $this->putJson('/api/admin/roles/'.$roleId.'/permissions', [
            'permissions' => ['system.menu.view'],
            'authorization' => 'Bearer should-not-log',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        /** @var Activity $roleActivity */
        $roleActivity = Activity::query()->where('subject_type', (new Role)->getMorphClass())->where('subject_id', $roleId)->latest('id')->firstOrFail();
        $this->assertSame('permissions_synced', $roleActivity->event);
        $this->assertSame('admin', $roleActivity->properties->get('guard'));
        $this->assertSame('admin.roles.permissions.update', $roleActivity->properties->get('route'));
        $this->assertActivityPropertiesAreSanitized($roleActivity);

        $target = User::factory()->create(['email' => 'role-target@example.com']);
        $this->putJson('/api/admin/users/'.$target->id.'/roles', [
            'roles' => ['auditor'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        /** @var Activity $userActivity */
        $userActivity = Activity::query()->where('subject_type', $target->getMorphClass())->where('subject_id', $target->id)->latest('id')->firstOrFail();
        $this->assertSame('roles_synced', $userActivity->event);
        $this->assertSame(['auditor'], $userActivity->properties->get('attributes')['roles']);
    }

    public function test_role_updates_are_audited_as_updates(): void
    {
        $this->createPermission('system.role.create');
        $this->createPermission('system.role.update');

        $admin = User::factory()->create(['email' => 'audit-role-update-admin@example.com']);
        $admin->givePermissionTo(['system.role.create', 'system.role.update']);
        $token = $this->adminTokenFor($admin);

        $roleResponse = $this->postJson('/api/admin/roles', [
            'name' => 'editable-role',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $roleId = $roleResponse->json('data.role.id');
        $this->assertIsInt($roleId);

        $this->patchJson('/api/admin/roles/'.$roleId, [
            'name' => 'edited-role',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $activity */
        $activity = Activity::query()->where('subject_type', (new Role)->getMorphClass())->where('subject_id', $roleId)->latest('id')->firstOrFail();

        $this->assertSame('updated', $activity->event);
        $this->assertSame('edited-role', $activity->properties->get('attributes')['name']);
        $this->assertSame('admin.roles.update', $activity->properties->get('route'));
        $this->assertActivityPropertiesAreSanitized($activity);
    }

    public function test_menu_create_rolls_back_when_activity_log_write_fails(): void
    {
        $this->createPermission('system.menu.create');

        $admin = User::factory()->create(['email' => 'audit-menu-rollback@example.com']);
        $admin->givePermissionTo('system.menu.create');
        $token = $this->adminTokenFor($admin);
        $this->useFailingActivityModel();

        $this->assertActivityLogFailure(function () use ($token): void {
            $this->postJson('/api/admin/menus', [
                'name' => 'Rollback Menu',
                'code' => 'rollback.menu',
                'path' => '/rollback/menu',
            ], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertDatabaseMissing('menus', [
            'code' => 'rollback.menu',
        ]);
    }

    public function test_system_config_update_rolls_back_when_activity_log_write_fails(): void
    {
        $this->createPermission('system.config.update');

        $config = SystemConfig::factory()->create([
            'name' => 'Rollback Config',
            'key' => 'rollback.config',
            'value' => 'before',
        ]);
        $admin = User::factory()->create(['email' => 'audit-config-rollback@example.com']);
        $admin->givePermissionTo('system.config.update');
        $token = $this->adminTokenFor($admin);
        $this->useFailingActivityModel();

        $this->assertActivityLogFailure(function () use ($config, $token): void {
            $this->patchJson('/api/admin/system-configs/'.$config->id, [
                'value' => 'after',
            ], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertSame('before', $config->refresh()->value);
    }

    public function test_admin_user_delete_rolls_back_when_activity_log_write_fails(): void
    {
        $this->createPermission('system.user.delete');

        $target = User::factory()->create(['email' => 'audit-delete-target@example.com']);
        $admin = User::factory()->create(['email' => 'audit-user-delete-rollback@example.com']);
        $admin->givePermissionTo('system.user.delete');
        $token = $this->adminTokenFor($admin);
        $this->useFailingActivityModel();

        $this->assertActivityLogFailure(function () use ($target, $token): void {
            $this->deleteJson('/api/admin/users/'.$target->id, [], ['Authorization' => 'Bearer '.$token]);
        });

        $this->assertModelExists($target);
    }

    private function createPermission(string $permissionName): Permission
    {
        $permission = Permission::findOrCreate($permissionName, 'admin');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permission;
    }

    private function adminTokenFor(User $user): string
    {
        $response = $this->postJson('/api/admin/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    private function assertActivityPropertiesAreSanitized(Activity $activity): void
    {
        $payload = $activity->properties->toJson();
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('secret-password', $payload);
        $this->assertStringNotContainsString('new-secret-password', $payload);
        $this->assertStringNotContainsString('should-not-log', $payload);
        $this->assertStringNotContainsString('authorization', strtolower($payload));
        $this->assertStringNotContainsString('token', strtolower($payload));
        $this->assertStringNotContainsString('jwt', strtolower($payload));
    }

    private function useFailingActivityModel(): void
    {
        config(['activitylog.activity_model' => FailingActivity::class]);
    }

    private function assertActivityLogFailure(callable $request): void
    {
        $this->withoutExceptionHandling();

        try {
            $request();
            $this->fail('Expected activity log write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Activity log write failed', $exception->getMessage());
        }
    }
}
