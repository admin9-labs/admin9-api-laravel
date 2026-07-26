<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
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
            $this->postAdminLogin('192.0.2.30')
                ->assertUnauthorized()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 401)
                ->assertJsonPath('message', 'Invalid credentials')
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', (string) (5 - $attempt));
        }

        $this->assertRateLimited($this->postAdminLogin('192.0.2.30'));
    }

    public function test_admin_login_rate_limit_is_isolated_by_client_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postAdminLogin('192.0.2.31')->assertUnauthorized();
        }

        $this->postAdminLogin('192.0.2.32')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertHeader('X-RateLimit-Remaining', '4');
        $this->assertRateLimited($this->postAdminLogin('192.0.2.31'));
    }

    public function test_admin_login_named_limiter_uses_the_namespaced_ip_bucket(): void
    {
        $limiter = RateLimiterFacade::limiter('admin-login');

        $this->assertNotNull($limiter);

        $request = Request::create(
            '/api/admin/auth/login',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.33'],
        );
        $limit = $limiter($request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame('admin:login:ip:192.0.2.33', $limit->key);
        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
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

    public function test_protected_member_api_limit_is_isolated_by_authenticated_member(): void
    {
        $firstMember = Member::factory()->create();
        $secondMember = Member::factory()->create();
        $firstToken = $this->memberToken($firstMember);
        $secondToken = $this->memberToken($secondMember);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->getMemberProfile($firstToken, '192.0.2.60')
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '30');
        }

        $this->getMemberProfile($secondToken, '192.0.2.60')
            ->assertOk()
            ->assertJsonPath('data.member.id', $secondMember->getKey());
    }

    public function test_protected_member_api_limit_follows_member_across_ip_addresses(): void
    {
        $member = Member::factory()->create();
        $token = $this->memberToken($member);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $ipAddress = $attempt % 2 === 0 ? '192.0.2.70' : '192.0.2.71';

            $this->getMemberProfile($token, $ipAddress)->assertOk();
        }

        $this->assertRateLimited(
            $this->getMemberProfile($token, '192.0.2.72'),
            expectedLimit: '30',
        );
    }

    public function test_guest_member_api_limit_continues_to_fall_back_to_client_ip(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->postMemberRefresh('192.0.2.80')->assertUnauthorized();
        }

        $this->postMemberRefresh('192.0.2.81')->assertUnauthorized();
        $this->assertRateLimited(
            $this->postMemberRefresh('192.0.2.80'),
            expectedLimit: '30',
        );
    }

    public function test_member_and_admin_login_routes_keep_their_scoped_limiters(): void
    {
        $memberLogin = RouteFacade::getRoutes()->getByName('member.auth.login');
        $adminLogin = RouteFacade::getRoutes()->getByName('admin.auth.login');

        $this->assertInstanceOf(Route::class, $memberLogin);
        $this->assertInstanceOf(Route::class, $adminLogin);
        $this->assertContains('throttle:member-api', $memberLogin->gatherMiddleware());
        $this->assertContains('throttle:member-login', $memberLogin->gatherMiddleware());
        $this->assertContains('throttle:admin-login', $adminLogin->gatherMiddleware());
    }

    public function test_admin_media_upload_limiter_uses_admin_id_and_ip_fallback_buckets(): void
    {
        $limiter = RateLimiterFacade::limiter('admin-media-upload');
        $this->assertNotNull($limiter);
        $admin = User::factory()->create();
        $authenticatedRequest = Request::create(
            '/api/admin/media',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.90'],
        );
        $authenticatedRequest->setUserResolver(
            static fn (?string $guard = null): ?User => $guard === 'admin' ? $admin : null,
        );
        $authenticatedLimit = $limiter($authenticatedRequest);

        $this->assertInstanceOf(Limit::class, $authenticatedLimit);
        $this->assertSame('admin:media-upload:user:'.$admin->getAuthIdentifier(), $authenticatedLimit->key);
        $this->assertSame(10, $authenticatedLimit->maxAttempts);
        $this->assertSame(60, $authenticatedLimit->decaySeconds);

        $guestRequest = Request::create(
            '/api/admin/media',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.91'],
        );
        $guestLimit = $limiter($guestRequest);

        $this->assertInstanceOf(Limit::class, $guestLimit);
        $this->assertSame('admin:media-upload:ip:192.0.2.91', $guestLimit->key);
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

    private function getMemberProfile(string $token, string $ipAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])->getJson('/api/auth/me', [
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    private function postMemberRefresh(string $ipAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])->postJson('/api/auth/refresh');
    }

    private function memberToken(Member $member): string
    {
        $guard = Auth::guard('member');

        $this->assertInstanceOf(JWTGuard::class, $guard);

        return $guard->login($member);
    }

    private function assertRateLimited(TestResponse $response, string $expectedLimit = '5'): void
    {
        $response
            ->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertJsonPath('data', [])
            ->assertJsonPath('errors', [])
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-RateLimit-Limit', $expectedLimit)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Reset');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertGreaterThan(time(), (int) $response->headers->get('X-RateLimit-Reset'));
    }
}
