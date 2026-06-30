<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Member;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MemberAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_member_can_login_with_email_view_refresh_and_logout(): void
    {
        $member = Member::factory()->create([
            'email' => 'member@example.com',
            'mobile' => '13900000000',
            'password' => 'password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'account' => 'member@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.member.id', $member->id)
            ->assertJsonPath('data.member.email', 'member@example.com')
            ->assertJsonPath('data.member.mobile', '13900000000')
            ->assertJsonMissingPath('data.member.password')
            ->assertHeader('X-Request-Id');

        $token = $login->json('data.access_token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.id', $member->id)
            ->assertJsonPath('data.member.email', 'member@example.com')
            ->assertHeader('X-Request-Id');

        $refresh = $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.id', $member->id)
            ->assertHeader('X-Request-Id');

        $refreshedToken = $refresh->json('data.access_token');
        $this->assertIsString($refreshedToken);
        $this->assertNotSame($token, $refreshedToken);

        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer '.$refreshedToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'logged out')
            ->assertHeader('X-Request-Id');

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$refreshedToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_member_can_login_with_mobile(): void
    {
        $member = Member::factory()->create([
            'email' => 'member@example.com',
            'mobile' => '13900000000',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'account' => '13900000000',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.id', $member->id)
            ->assertJsonPath('data.member.mobile', '13900000000')
            ->assertHeader('X-Request-Id');
    }

    public function test_member_login_rejects_invalid_credentials(): void
    {
        Member::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'account' => 'member@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertHeader('X-Request-Id');
    }

    public function test_member_login_and_existing_tokens_reject_disabled_accounts(): void
    {
        $member = Member::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'account' => 'member@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $member->forceFill(['is_active' => false])->save();

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('message', 'Account disabled')
            ->assertHeader('X-Request-Id');

        $this->postJson('/api/auth/login', [
            'account' => 'member@example.com',
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertHeader('X-Request-Id');

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'member',
            'account' => 'member@example.com',
            'event' => 'login',
            'successful' => false,
            'failure_reason' => 'Account disabled',
        ]);
    }
}
