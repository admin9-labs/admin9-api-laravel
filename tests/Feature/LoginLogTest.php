<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoginLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_login_success_failure_refresh_and_logout_are_recorded(): void
    {
        $user = User::factory()->create([
            'email' => 'log-admin@example.com',
            'password' => 'password',
        ]);

        $failure = $this->postJson('/api/admin/auth/login', [
            'email' => 'log-admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'admin',
            'account' => 'log-admin@example.com',
            'event' => 'login',
            'successful' => false,
            'failure_reason' => 'Invalid credentials',
            'request_id' => $failure->json('request_id'),
        ]);

        $login = $this->postJson('/api/admin/auth/login', [
            'email' => 'log-admin@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $token = $login->json('data.access_token');
        $this->assertIsString($token);

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'admin',
            'account' => 'log-admin@example.com',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'event' => 'login',
            'successful' => true,
            'request_id' => $login->json('request_id'),
        ]);

        $refresh = $this->postJson('/api/admin/auth/refresh', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();
        $refreshedToken = $refresh->json('data.access_token');
        $this->assertIsString($refreshedToken);

        $logout = $this->postJson('/api/admin/auth/logout', [], ['Authorization' => 'Bearer '.$refreshedToken])
            ->assertOk();

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'admin',
            'event' => 'refresh',
            'successful' => true,
            'request_id' => $refresh->json('request_id'),
        ]);
        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'admin',
            'event' => 'logout',
            'successful' => true,
            'request_id' => $logout->json('request_id'),
        ]);

        $this->assertLoginLogsDoNotContainSensitiveValues();
    }

    public function test_member_login_success_and_failure_are_recorded(): void
    {
        $member = Member::factory()->create([
            'email' => 'log-member@example.com',
            'mobile' => '13900000000',
            'password' => 'password',
        ]);

        $failure = $this->postJson('/api/auth/login', [
            'account' => '13900000000',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'member',
            'account' => '13900000000',
            'event' => 'login',
            'successful' => false,
            'failure_reason' => 'Invalid credentials',
            'request_id' => $failure->json('request_id'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'account' => 'log-member@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => 'member',
            'account' => 'log-member@example.com',
            'subject_type' => $member->getMorphClass(),
            'subject_id' => $member->id,
            'event' => 'login',
            'successful' => true,
            'request_id' => $login->json('request_id'),
        ]);

        $this->assertLoginLogsDoNotContainSensitiveValues();
    }

    private function assertLoginLogsDoNotContainSensitiveValues(): void
    {
        $payload = LoginLog::query()->get()->toJson();
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('wrong-password', $payload);
        $this->assertStringNotContainsString('password', strtolower($payload));
        $this->assertStringNotContainsString('authorization', strtolower($payload));
        $this->assertStringNotContainsString('bearer', strtolower($payload));
        $this->assertStringNotContainsString('jwt', strtolower($payload));
    }
}
