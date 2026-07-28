<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FailingActivity;
use Tests\TestCase;

class ActivityAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_menu_changes_are_audited_with_request_context_and_sensitive_fields_filtered(): void
    {
        $createPermission = $this->createPermission('system.menu.create');
        $updatePermission = $this->createPermission('system.menu.update');

        $admin = User::factory()->create(['email' => 'audit-menu@example.com']);
        $admin->givePermissionTo(['system.menu.create', 'system.menu.update']);
        $token = $this->adminTokenFor($admin);

        $create = $this->postJson('/api/admin/menus', [
            'name' => '审计菜单',
            'code' => 'audit.menu',
            'path' => '/audit/menu',
            'permission_ids' => [$createPermission->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $menuId = $create->json('data.menu.id');
        $this->assertIsInt($menuId);

        $this->patchJson('/api/admin/menus/'.$menuId, [
            'name' => '审计菜单更新',
            'permission_ids' => [$updatePermission->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $permissionActivity */
        $permissionActivity = Activity::query()
            ->where('subject_type', (new Menu)->getMorphClass())
            ->where('subject_id', $menuId)
            ->where('event', 'permissions_synced')
            ->latest('id')
            ->firstOrFail();
        $properties = $permissionActivity->properties->toArray();

        $this->assertSame('admin', $permissionActivity->log_name);
        $this->assertSame('permissions_synced', $permissionActivity->event);
        $this->assertSame('admin', $properties['guard']);
        $this->assertSame('admin.menus.update', $properties['route']);
        $this->assertNotEmpty($properties['request_id']);
        $this->assertSame('127.0.0.1', $properties['ip_address']);
        $this->assertSame($admin->id, $permissionActivity->causer_id);
        $this->assertSame([$createPermission->id], $properties['old']['permission_ids']);
        $this->assertSame([$createPermission->name], $properties['old']['permission_names']);
        $this->assertSame([$updatePermission->id], $properties['attributes']['permission_ids']);
        $this->assertSame([$updatePermission->name], $properties['attributes']['permission_names']);

        /** @var Activity $updatedActivity */
        $updatedActivity = Activity::query()
            ->where('subject_type', (new Menu)->getMorphClass())
            ->where('subject_id', $menuId)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('审计菜单更新', $updatedActivity->properties->get('attributes')['name']);

        $this->assertActivityPropertiesAreSanitized($permissionActivity);
    }

    public function test_admin_user_creation_and_updates_do_not_store_passwords_in_activity_properties(): void
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

        /** @var Activity $createdActivity */
        $createdActivity = Activity::query()
            ->where('subject_type', (new User)->getMorphClass())
            ->where('subject_id', $userId)
            ->where('event', 'created')
            ->firstOrFail();
        $this->assertActivityPropertiesAreSanitized($createdActivity);

        $this->patchJson('/api/admin/users/'.$userId, [
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

    public function test_permission_create_update_and_delete_are_audited(): void
    {
        $this->createPermission('system.permission.create');
        $this->createPermission('system.permission.update');
        $this->createPermission('system.permission.delete');

        $admin = User::factory()->create(['email' => 'audit-permission-admin@example.com']);
        $admin->givePermissionTo([
            'system.permission.create',
            'system.permission.update',
            'system.permission.delete',
        ]);
        $token = $this->adminTokenFor($admin);

        $create = $this->postJson('/api/admin/permissions', [
            'name' => 'dynamic.audit.lifecycle',
            'display_name' => 'Audit lifecycle',
            'group' => 'dynamic.audit',
            'description' => 'Permission lifecycle audit coverage',
            'sort' => 80,
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $permissionId = $create->json('data.permission.id');
        $this->assertIsInt($permissionId);
        $permission = Permission::query()->findOrFail($permissionId);

        /** @var Activity $created */
        $created = Activity::query()
            ->where('subject_type', $permission->getMorphClass())
            ->where('subject_id', $permissionId)
            ->where('event', 'created')
            ->firstOrFail();
        $this->assertSame('dynamic.audit.lifecycle', $created->properties->get('attributes')['name']);
        $this->assertSame('admin.permissions.store', $created->properties->get('route'));
        $this->assertActivityPropertiesAreSanitized($created);

        $this->patchJson('/api/admin/permissions/'.$permissionId, [
            'display_name' => 'Audit lifecycle updated',
            'sort' => 90,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $updated */
        $updated = Activity::query()
            ->where('subject_type', $permission->getMorphClass())
            ->where('subject_id', $permissionId)
            ->where('event', 'updated')
            ->firstOrFail();
        $this->assertSame('Audit lifecycle updated', $updated->properties->get('attributes')['display_name']);
        $this->assertSame('Audit lifecycle', $updated->properties->get('old')['display_name']);
        $this->assertSame('admin.permissions.update', $updated->properties->get('route'));
        $this->assertActivityPropertiesAreSanitized($updated);

        $this->deleteJson('/api/admin/permissions/'.$permissionId, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $deleted */
        $deleted = Activity::query()
            ->where('subject_type', $permission->getMorphClass())
            ->where('subject_id', $permissionId)
            ->where('event', 'deleted')
            ->firstOrFail();
        $this->assertSame('dynamic.audit.lifecycle', $deleted->properties->get('old')['name']);
        $this->assertSame('admin.permissions.destroy', $deleted->properties->get('route'));
        $this->assertActivityPropertiesAreSanitized($deleted);
    }

    public function test_password_changed_and_reset_events_do_not_store_plaintext_or_hashes(): void
    {
        $this->createPermission('system.user.update');

        $resetActor = User::factory()->create(['email' => 'audit-password-reset-actor@example.com']);
        $resetActor->givePermissionTo('system.user.update');
        $resetToken = $this->adminTokenFor($resetActor);
        $resetTarget = User::factory()->create(['email' => 'audit-password-reset-target@example.com']);
        $resetOldHash = $resetTarget->password;

        $this->putJson('/api/admin/users/'.$resetTarget->id.'/password', [
            'password' => 'Reset-password-123',
            'password_confirmation' => 'Reset-password-123',
        ], ['Authorization' => 'Bearer '.$resetToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $resetActivity */
        $resetActivity = Activity::query()
            ->where('subject_type', $resetTarget->getMorphClass())
            ->where('subject_id', $resetTarget->id)
            ->where('event', 'password_reset')
            ->firstOrFail();
        $this->assertSame($resetActor->id, $resetActivity->causer_id);
        $this->assertSame('admin.users.password.update', $resetActivity->properties->get('route'));
        $this->assertCredentialActivityContainsNoSecrets($resetActivity, [
            'Reset-password-123',
            $resetOldHash,
            $resetTarget->refresh()->password,
        ]);

        $adminCurrentPassword = 'Current-admin-credential-123';
        $admin = User::factory()->create([
            'email' => 'audit-password-change-admin@example.com',
            'password' => $adminCurrentPassword,
        ]);
        $adminToken = $this->adminTokenFor($admin, $adminCurrentPassword);
        $adminOldHash = $admin->password;

        $this->putJson('/api/admin/auth/password', [
            'current_password' => $adminCurrentPassword,
            'password' => 'Changed-admin-password-123',
            'password_confirmation' => 'Changed-admin-password-123',
        ], ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $adminActivity */
        $adminActivity = Activity::query()
            ->where('subject_type', $admin->getMorphClass())
            ->where('subject_id', $admin->id)
            ->where('event', 'password_changed')
            ->firstOrFail();
        $this->assertSame($admin->id, $adminActivity->causer_id);
        $this->assertSame('admin.auth.password.update', $adminActivity->properties->get('route'));
        $this->assertCredentialActivityContainsNoSecrets($adminActivity, [
            $adminCurrentPassword,
            'Changed-admin-password-123',
            $adminOldHash,
            $admin->refresh()->password,
        ]);

        $memberCurrentPassword = 'Current-member-credential-123';
        $member = Member::factory()->create([
            'email' => 'audit-password-change-member@example.com',
            'password' => $memberCurrentPassword,
        ]);
        $memberLogin = $this->postJson('/api/auth/login', [
            'account' => $member->email,
            'password' => $memberCurrentPassword,
        ])->assertOk();
        $memberToken = $memberLogin->json('data.access_token');
        $this->assertIsString($memberToken);
        $memberOldHash = $member->password;

        $this->putJson('/api/auth/password', [
            'current_password' => $memberCurrentPassword,
            'password' => 'Changed-member-password-123',
            'password_confirmation' => 'Changed-member-password-123',
        ], ['Authorization' => 'Bearer '.$memberToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $memberActivity */
        $memberActivity = Activity::query()
            ->where('subject_type', $member->getMorphClass())
            ->where('subject_id', $member->id)
            ->where('event', 'password_changed')
            ->firstOrFail();
        $this->assertSame($member->id, $memberActivity->causer_id);
        $this->assertSame('member.auth.password.update', $memberActivity->properties->get('route'));
        $this->assertCredentialActivityContainsNoSecrets($memberActivity, [
            $memberCurrentPassword,
            'Changed-member-password-123',
            $memberOldHash,
            $member->refresh()->password,
        ]);
    }

    public function test_system_config_values_are_never_persisted_or_exposed_in_activity_logs(): void
    {
        $this->createPermission('system.config.create');
        $this->createPermission('system.config.update');
        $this->createPermission('system.config.delete');
        $this->createPermission('system.activity-log.view');

        $admin = User::factory()->create(['email' => 'audit-config-admin@example.com']);
        $admin->givePermissionTo(['system.config.create', 'system.config.update', 'system.config.delete']);
        $token = $this->adminTokenFor($admin);
        $configKey = 'integration.credential';
        $createdValue = 'sk_live_51AuditCreateValue';
        $updatedValue = 'ghp_AuditUpdatedOpaqueValue';
        $ordinaryValue = 'Welcome to Admin9';

        $create = $this->postJson('/api/admin/system-configs', [
            'name' => 'Integration Credential',
            'key' => $configKey,
            'value' => $createdValue,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $configId = $create->json('data.system_config.id');
        $this->assertIsInt($configId);

        /** @var Activity $createdActivity */
        $createdActivity = Activity::query()
            ->where('subject_type', (new SystemConfig)->getMorphClass())
            ->where('subject_id', $configId)
            ->where('event', 'created')
            ->firstOrFail();
        $this->assertSystemConfigValueIsNotLogged($createdActivity, $configKey, [$createdValue]);

        $this->patchJson('/api/admin/system-configs/'.$configId, [
            'value' => $updatedValue,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $credentialUpdateActivity */
        $credentialUpdateActivity = Activity::query()
            ->where('subject_type', (new SystemConfig)->getMorphClass())
            ->where('subject_id', $configId)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSystemConfigValueIsNotLogged($credentialUpdateActivity, $configKey, [$createdValue, $updatedValue]);

        $this->patchJson('/api/admin/system-configs/'.$configId, [
            'value' => $ordinaryValue,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $ordinaryUpdateActivity */
        $ordinaryUpdateActivity = Activity::query()
            ->where('subject_type', (new SystemConfig)->getMorphClass())
            ->where('subject_id', $configId)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSystemConfigValueIsNotLogged($ordinaryUpdateActivity, $configKey, [$updatedValue, $ordinaryValue]);

        $viewer = User::factory()->create(['email' => 'audit-config-viewer@example.com']);
        $viewer->givePermissionTo('system.activity-log.view');
        $viewerToken = $this->adminTokenFor($viewer);
        $query = http_build_query([
            'subject_type' => (new SystemConfig)->getMorphClass(),
            'subject_id' => $configId,
        ]);

        $activityResponse = $this->getJson('/api/admin/activity-logs?'.$query, [
            'Authorization' => 'Bearer '.$viewerToken,
        ])->assertOk()->assertJsonPath('success', true);
        $activityPayload = $activityResponse->getContent();
        $this->assertStringNotContainsString($createdValue, $activityPayload);
        $this->assertStringNotContainsString($updatedValue, $activityPayload);
        $this->assertStringNotContainsString($ordinaryValue, $activityPayload);

        $this->deleteJson('/api/admin/system-configs/'.$configId, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var Activity $deletedActivity */
        $deletedActivity = Activity::query()
            ->where('subject_type', (new SystemConfig)->getMorphClass())
            ->where('subject_id', $configId)
            ->where('event', 'deleted')
            ->firstOrFail();
        $this->assertSystemConfigValueIsNotLogged($deletedActivity, $configKey, [$ordinaryValue]);
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

    private function adminTokenFor(User $user, string $password = 'password'): string
    {
        $response = $this->postJson('/api/admin/auth/login', [
            'email' => $user->email,
            'password' => $password,
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

    /**
     * @param  array<int, string>  $values
     */
    private function assertSystemConfigValueIsNotLogged(Activity $activity, string $configKey, array $values): void
    {
        $properties = $activity->properties->toArray();

        $this->assertSame($configKey, $properties['config_key']);
        $this->assertTrue($properties['value_changed']);
        $this->assertArrayNotHasKey('value', $properties['attributes'] ?? []);
        $this->assertArrayNotHasKey('value', $properties['old'] ?? []);

        $payload = $activity->properties->toJson();
        $this->assertIsString($payload);

        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }
    }

    /**
     * @param  array<int, string>  $secrets
     */
    private function assertCredentialActivityContainsNoSecrets(Activity $activity, array $secrets): void
    {
        $properties = $activity->properties->toArray();
        $this->assertPayloadHasNoSensitiveKeys($properties);

        $payload = $activity->properties->toJson();
        $this->assertIsString($payload);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function assertPayloadHasNoSensitiveKeys(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/password|token|secret|jwt|authorization|api[\s._-]*key/i',
                    $key,
                );
            }

            if (is_array($value)) {
                $this->assertPayloadHasNoSensitiveKeys($value);
            }
        }
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
