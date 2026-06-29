<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GuardIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_token_cannot_access_member_routes(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $adminToken = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', -1)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_member_token_cannot_access_admin_routes(): void
    {
        Member::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password',
        ]);

        $memberToken = $this->postJson('/api/auth/login', [
            'account' => 'member@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$memberToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', -1)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_admin_and_member_can_share_numeric_ids_without_cross_guard_access(): void
    {
        $user = User::factory()->create([
            'email' => 'same-id-admin@example.com',
            'password' => 'password',
        ]);

        $member = Member::factory()->create([
            'id' => $user->id,
            'email' => 'same-id-member@example.com',
            'password' => 'password',
        ]);

        $adminToken = $this->postJson('/api/admin/auth/login', [
            'email' => 'same-id-admin@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $memberToken = $this->postJson('/api/auth/login', [
            'account' => 'same-id-member@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id);

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$memberToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.id', $member->id);

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$memberToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_member_guard_permission_record_cannot_satisfy_admin_route_mapping(): void
    {
        Permission::findOrCreate('system.role.view', 'member');

        $adminToken = $this->postJson('/api/admin/auth/login', [
            'email' => User::factory()->create(['email' => 'guard-denied@example.com'])->email,
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertHeader('X-Request-Id');
    }
}
