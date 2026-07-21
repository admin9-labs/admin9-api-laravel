<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RateLimitContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_member_login_allows_five_attempts_before_throttling(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postMemberLogin('192.0.2.10')
                ->assertUnauthorized()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 401)
                ->assertJsonPath('message', 'Invalid credentials');
        }

        $this->assertRateLimited($this->postMemberLogin('192.0.2.10'));
    }

    public function test_member_login_rate_limit_is_isolated_by_client_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postMemberLogin('192.0.2.20')->assertUnauthorized();
        }

        $this->postMemberLogin('192.0.2.21')->assertUnauthorized();
        $this->assertRateLimited($this->postMemberLogin('192.0.2.20'));
    }

    public function test_admin_login_preserves_its_existing_rate_limit_contract(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postAdminLogin('192.0.2.30')->assertUnauthorized();
        }

        $this->assertRateLimited($this->postAdminLogin('192.0.2.30'));
    }

    public function test_member_login_attempts_do_not_consume_the_admin_login_limit(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postMemberLogin('192.0.2.40')->assertUnauthorized();
        }

        $this->postAdminLogin('192.0.2.40')->assertUnauthorized();
        $this->assertRateLimited($this->postMemberLogin('192.0.2.40'));
    }

    public function test_admin_login_attempts_do_not_consume_the_member_login_limit(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postAdminLogin('192.0.2.50')->assertUnauthorized();
        }

        $this->postMemberLogin('192.0.2.50')->assertUnauthorized();
        $this->assertRateLimited($this->postAdminLogin('192.0.2.50'));
    }

    public function test_member_and_admin_login_routes_keep_their_scoped_limiters(): void
    {
        $memberLogin = RouteFacade::getRoutes()->getByName('member.auth.login');
        $adminLogin = RouteFacade::getRoutes()->getByName('admin.auth.login');

        $this->assertInstanceOf(Route::class, $memberLogin);
        $this->assertInstanceOf(Route::class, $adminLogin);
        $this->assertContains('throttle:member-api', $memberLogin->gatherMiddleware());
        $this->assertContains('throttle:member-login', $memberLogin->gatherMiddleware());
        $this->assertContains('throttle:5,1', $adminLogin->gatherMiddleware());
    }

    private function postMemberLogin(string $ipAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])->postJson('/api/auth/login', [
            'account' => 'missing-member@example.com',
            'password' => 'wrong-password',
        ]);
    }

    private function postAdminLogin(string $ipAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])->postJson('/api/admin/auth/login', [
            'email' => 'missing-admin@example.com',
            'password' => 'wrong-password',
        ]);
    }

    private function assertRateLimited(TestResponse $response): void
    {
        $response
            ->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Reset');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertGreaterThan(time(), (int) $response->headers->get('X-RateLimit-Reset'));
    }
}
