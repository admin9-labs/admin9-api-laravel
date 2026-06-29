<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminPermissionManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_authorized_user_can_list_permissions_with_metadata(): void
    {
        $this->createPermission('dynamic.report.view', [
            'display_name' => '报表查看',
            'group' => 'dynamic.report',
            'description' => 'View dynamic reports',
        ]);
        $token = $this->managerTokenFor(['system.permission.view']);

        $response = $this->getJson('/api/admin/permissions', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'dynamic.report.view'])
            ->assertJsonFragment(['display_name' => '报表查看']);

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertArrayNotHasKey('routes', $response->json('data.0'));
        $this->assertArrayNotHasKey('route_names', $response->json('data.0'));
    }

    public function test_authorized_user_can_list_complete_permission_catalog_in_group_sort_name_order(): void
    {
        $this->createPermission('dynamic.catalog.gamma', ['group' => 'dynamic.catalog', 'sort' => 10]);
        $this->createPermission('dynamic.catalog.alpha', ['group' => 'dynamic.catalog', 'sort' => 10]);
        $this->createPermission('dynamic.catalog.beta', ['group' => 'dynamic.catalog', 'sort' => 20]);
        $token = $this->managerTokenFor(['system.permission.view']);

        $response = $this->getJson('/api/admin/permissions?page_size=1', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $permissionNames = collect($response->json('data'))
            ->pluck('name')
            ->filter(fn (string $name): bool => str_starts_with($name, 'dynamic.catalog.'))
            ->values()
            ->all();

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertSame([
            'dynamic.catalog.alpha',
            'dynamic.catalog.gamma',
            'dynamic.catalog.beta',
        ], $permissionNames);
    }

    public function test_authorized_user_can_list_complete_role_catalog_without_pagination(): void
    {
        foreach (range(1, 25) as $number) {
            Role::findOrCreate("catalog-role-{$number}", 'admin');
        }
        $token = $this->managerTokenFor(['system.role.view']);

        $response = $this->getJson('/api/admin/roles?page_size=2', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $roleNames = collect($response->json('data'))
            ->pluck('name')
            ->filter(fn (string $name): bool => str_starts_with($name, 'catalog-role-'))
            ->values()
            ->all();

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertCount(25, $roleNames);
        $this->assertContains('catalog-role-25', $roleNames);
    }

    public function test_authorized_user_can_create_dynamic_permission(): void
    {
        $token = $this->managerTokenFor(['system.permission.create']);

        $this->postJson('/api/admin/permissions', [
            'name' => 'dynamic.audit.view',
            'display_name' => '审计查看',
            'group' => 'dynamic.audit',
            'description' => 'View audit data',
            'sort' => 50,
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'dynamic.audit.view'])
            ->assertJsonFragment(['guard_name' => 'admin']);

        $this->assertDatabaseHas('permissions', [
            'name' => 'dynamic.audit.view',
            'guard_name' => 'admin',
            'display_name' => '审计查看',
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    public function test_assigned_canonical_permission_authorizes_declared_route(): void
    {
        $permission = $this->createPermission('system.role.view');

        $user = User::factory()->create(['email' => 'canonical-allow@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_dynamic_permission_does_not_authorize_routes_without_explicit_route_middleware(): void
    {
        $permission = $this->createPermission('dynamic.roles.index');

        $user = User::factory()->create(['email' => 'dynamic-no-route-binding@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    public function test_deactivated_permission_immediately_denies_declared_route(): void
    {
        $permission = $this->createPermission('system.role.view');
        $managerToken = $this->managerTokenFor(['system.permission.update']);

        $user = User::factory()->create(['email' => 'dynamic-inactive@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->patchJson('/api/admin/permissions/'.$permission->id, [
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$managerToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    public function test_duplicate_admin_permission_name_returns_validation_error(): void
    {
        $this->createPermission('dynamic.duplicate');
        $token = $this->managerTokenFor(['system.permission.create']);

        $this->postJson('/api/admin/permissions', [
            'name' => 'dynamic.duplicate',
            'display_name' => 'Duplicate',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);
    }

    public function test_member_guard_records_are_hidden_from_admin_role_and_permission_endpoints(): void
    {
        $token = $this->managerTokenFor(['system.permission.view', 'system.role.view']);

        $memberPermission = Permission::findOrCreate('member.hidden.permission', 'member');
        $memberRole = Role::findOrCreate('member-hidden-role', 'member');

        $this->getJson('/api/admin/permissions/'.$memberPermission->id, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 404);

        $this->getJson('/api/admin/roles/'.$memberRole->id, ['Authorization' => 'Bearer '.$token])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 404);
    }

    public function test_system_permission_cannot_be_deleted(): void
    {
        $permission = $this->createPermission('dynamic.system.protected', ['is_system' => true]);
        $token = $this->managerTokenFor(['system.permission.delete']);

        $this->deleteJson('/api/admin/permissions/'.$permission->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_assigned_permission_cannot_be_deleted(): void
    {
        $permission = $this->createPermission('dynamic.assigned.protected');
        Role::findOrCreate('operator', 'admin')->givePermissionTo($permission);
        $token = $this->managerTokenFor(['system.permission.delete']);

        $this->deleteJson('/api/admin/permissions/'.$permission->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_unassigned_dynamic_permission_can_be_deleted(): void
    {
        $permission = $this->createPermission('dynamic.unassigned.deletable');
        $token = $this->managerTokenFor(['system.permission.delete']);

        $this->deleteJson('/api/admin/permissions/'.$permission->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
    }
}
