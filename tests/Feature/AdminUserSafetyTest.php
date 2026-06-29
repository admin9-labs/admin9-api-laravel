<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $manager = User::factory()->create(['email' => 'last-super-manager@example.com']);
        $manager->givePermissionTo(['system.user.update', 'system.user.delete']);
        $token = $this->adminTokenFor($manager);

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
        $manager = User::factory()->create(['email' => 'ordinary-manager@example.com']);
        $manager->givePermissionTo(['system.user.update', 'system.user.delete']);
        $token = $this->adminTokenFor($manager);

        $this->patchJson('/api/admin/users/'.$target->id, [
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($target->refresh()->is_active);

        $this->deleteJson('/api/admin/users/'.$target->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing($target);
    }
}
