<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminUserSafetyTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_admin_cannot_disable_self(): void
    {
        $this->createPermission('system.user.update');
        $user = User::factory()->create(['email' => 'self-disable@example.com']);
        $user->givePermissionTo('system.user.update');
        $token = $this->adminTokenFor($user);

        $this->patchJson('/api/admin/users/'.$user->id, [
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $this->createPermission('system.user.delete');
        $user = User::factory()->create(['email' => 'self-delete@example.com']);
        $user->givePermissionTo('system.user.delete');
        $token = $this->adminTokenFor($user);

        $this->deleteJson('/api/admin/users/'.$user->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertModelExists($user);
    }

    public function test_last_active_super_admin_cannot_be_disabled_or_deleted(): void
    {
        $this->createPermission('system.user.update');
        $this->createPermission('system.user.delete');

        $superAdmin = $this->createSuperAdmin('last-super-user@example.com');
        $actor = $this->createSuperAdmin('inactive-super-actor@example.com');
        $token = $this->adminTokenFor($actor);
        $actor->update(['is_active' => false]);
        $this->withoutMiddleware(EnsureAccountIsActive::class);

        $this->patchJson('/api/admin/users/'.$superAdmin->id, [
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->deleteJson('/api/admin/users/'.$superAdmin->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertTrue($superAdmin->refresh()->is_active);
        $this->assertModelExists($superAdmin);
    }

    public function test_ordinary_target_user_can_still_be_disabled_and_deleted(): void
    {
        $this->createPermission('system.user.update');
        $this->createPermission('system.user.delete');

        $target = User::factory()->create(['email' => 'ordinary-target@example.com']);
        $originalAuthenticationVersion = $target->auth_version;
        $manager = User::factory()->create(['email' => 'ordinary-manager@example.com']);
        $manager->givePermissionTo(['system.user.update', 'system.user.delete']);
        $token = $this->adminTokenFor($manager);

        $this->patchJson('/api/admin/users/'.$target->id, [
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($target->refresh()->is_active);
        $this->assertSame($originalAuthenticationVersion + 1, $target->auth_version);

        $this->deleteJson('/api/admin/users/'.$target->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing($target);
    }

    public function test_non_super_admin_cannot_update_or_delete_reserved_role_holders(): void
    {
        $this->createPermission('system.user.update');
        $this->createPermission('system.user.delete');

        $superAdmin = $this->createSuperAdmin('protected-super-admin@example.com');
        $this->createSuperAdmin('remaining-super-admin@example.com');
        $systemAdmin = User::factory()->create(['email' => 'protected-system-admin@example.com']);
        $systemAdmin->assignRole(Role::findOrCreate('system-admin', 'admin'));
        $manager = User::factory()->create(['email' => 'reserved-role-manager@example.com']);
        $manager->givePermissionTo(['system.user.update', 'system.user.delete']);
        $token = $this->adminTokenFor($manager);

        $this->patchJson('/api/admin/users/'.$superAdmin->id, [
            'email' => 'changed-super-admin@example.com',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('errors.user.0', 'Only super-admin users may manage accounts with reserved admin roles.');

        $this->deleteJson('/api/admin/users/'.$superAdmin->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('errors.user.0', 'Only super-admin users may manage accounts with reserved admin roles.');

        $this->patchJson('/api/admin/users/'.$systemAdmin->id, [
            'name' => 'Changed System Admin',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('errors.user.0', 'Only super-admin users may manage accounts with reserved admin roles.');

        $this->deleteJson('/api/admin/users/'.$systemAdmin->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('errors.user.0', 'Only super-admin users may manage accounts with reserved admin roles.');

        $this->assertSame('protected-super-admin@example.com', $superAdmin->refresh()->email);
        $this->assertModelExists($superAdmin);
        $this->assertNotSame('Changed System Admin', $systemAdmin->refresh()->name);
        $this->assertModelExists($systemAdmin);
    }

    public function test_super_admin_can_update_and_delete_other_reserved_role_holders(): void
    {
        $this->createPermission('system.user.update');
        $this->createPermission('system.user.delete');

        $actor = $this->createSuperAdmin('reserved-role-super-actor@example.com');
        $targetSuperAdmin = $this->createSuperAdmin('manageable-super-admin@example.com');
        $targetSystemAdmin = User::factory()->create(['email' => 'manageable-system-admin@example.com']);
        $targetSystemAdmin->assignRole(Role::findOrCreate('system-admin', 'admin'));
        $token = $this->adminTokenFor($actor);

        $this->patchJson('/api/admin/users/'.$targetSuperAdmin->id, [
            'email' => 'updated-super-admin@example.com',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->deleteJson('/api/admin/users/'.$targetSuperAdmin->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->patchJson('/api/admin/users/'.$targetSystemAdmin->id, [
            'name' => 'Updated System Admin',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->deleteJson('/api/admin/users/'.$targetSystemAdmin->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing($targetSuperAdmin);
        $this->assertModelMissing($targetSystemAdmin);
        $this->assertModelExists($actor);
    }

    public function test_disabling_an_admin_invalidates_its_existing_access_and_refresh_flow_tokens(): void
    {
        $this->createPermission('system.user.update');

        $target = User::factory()->create(['email' => 'disabled-token-target@example.com']);
        $accessToken = $this->adminTokenFor($target);
        $tokenToRefresh = $this->adminTokenFor($target);
        $this->assertNotSame($accessToken, $tokenToRefresh);
        $refreshFlowToken = $this->postJson('/api/admin/auth/refresh', [], [
            'Authorization' => 'Bearer '.$tokenToRefresh,
        ])->assertOk()->json('data.access_token');
        $this->assertIsString($refreshFlowToken);

        $manager = User::factory()->create(['email' => 'disabled-token-manager@example.com']);
        $manager->givePermissionTo('system.user.update');
        $managerToken = $this->adminTokenFor($manager);
        $originalAuthenticationVersion = $target->auth_version;

        $this->patchJson('/api/admin/users/'.$target->id, [
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$managerToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($target->refresh()->is_active);
        $this->assertSame($originalAuthenticationVersion + 1, $target->auth_version);

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$accessToken])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated');

        $this->postJson('/api/admin/auth/refresh', [], ['Authorization' => 'Bearer '.$refreshFlowToken])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated');
    }
}
