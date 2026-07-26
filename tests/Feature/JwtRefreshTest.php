<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\Manager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JwtRefreshTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('guardProvider')]
    public function test_unexpired_access_token_can_be_refreshed(string $guard): void
    {
        [$account, $token] = $this->login($guard);

        $refresh = $this->postJson(
            $this->refreshUri($guard),
            headers: $this->authorizationHeader($token),
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath($this->identityPath($guard), $account->getKey())
            ->assertHeader('X-Request-Id');

        $refreshedToken = $refresh->json('data.access_token');
        $this->assertIsString($refreshedToken);
        $this->assertNotSame($token, $refreshedToken);
    }

    #[DataProvider('guardProvider')]
    public function test_expired_access_token_within_refresh_ttl_can_be_refreshed(string $guard): void
    {
        [$account, $token] = $this->login($guard);

        $this->travel((int) config('jwt.ttl') + 1)->minutes();

        $refresh = $this->postJson(
            $this->refreshUri($guard),
            headers: $this->authorizationHeader($token),
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath($this->identityPath($guard), $account->getKey())
            ->assertHeader('X-Request-Id');

        $this->assertIsString($refresh->json('data.access_token'));
    }

    #[DataProvider('guardProvider')]
    public function test_token_outside_refresh_ttl_cannot_be_refreshed(string $guard): void
    {
        [, $token] = $this->login($guard);

        $this->travel((int) config('jwt.refresh_ttl') + 1)->minutes();

        $this->assertUnauthenticatedRefresh($guard, $token);
    }

    #[DataProvider('guardProvider')]
    public function test_logged_out_blacklisted_token_cannot_be_refreshed(string $guard): void
    {
        [, $token] = $this->login($guard);

        $this->postJson($this->logoutUri($guard), headers: $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertUnauthenticatedRefresh($guard, $token);
    }

    #[DataProvider('guardProvider')]
    public function test_disabled_account_cannot_refresh_an_expired_access_token(string $guard): void
    {
        [$account, $token] = $this->login($guard);
        $account->forceFill(['is_active' => false])->save();
        $this->travel((int) config('jwt.ttl') + 1)->minutes();

        $this->postJson($this->refreshUri($guard), headers: $this->authorizationHeader($token))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('message', 'Account disabled')
            ->assertJsonPath('error_code', 'account_inactive')
            ->assertHeader('X-Request-Id');
    }

    #[DataProvider('guardProvider')]
    public function test_opposite_guard_token_cannot_be_refreshed(string $guard): void
    {
        $sharedAccountId = max(
            (int) User::query()->max('id'),
            (int) Member::query()->max('id'),
        ) + 1;

        [$admin, $adminToken] = $this->login('admin', $sharedAccountId);
        [$member, $memberToken] = $this->login('member', $sharedAccountId);
        $this->assertSame($admin->getKey(), $member->getKey());

        $oppositeToken = $guard === 'admin' ? $memberToken : $adminToken;

        $this->assertUnauthenticatedRefresh($guard, $oppositeToken);
    }

    #[DataProvider('missingIsolationClaimProvider')]
    public function test_token_missing_an_isolation_claim_cannot_be_refreshed(string $guard, string $missingClaim): void
    {
        $account = $guard === 'admin'
            ? User::factory()->create(['email' => 'missing-claim-admin@example.com'])
            : Member::factory()->create(['email' => 'missing-claim-member@example.com']);

        $claims = [
            'sub' => $account->getKey(),
            'guard' => $guard,
            'prv' => sha1($account::class),
        ];
        unset($claims[$missingClaim]);

        $this->assertUnauthenticatedRefresh($guard, $this->tokenWithClaims($claims));
    }

    #[DataProvider('guardProvider')]
    public function test_token_missing_authentication_version_cannot_be_refreshed(string $guard): void
    {
        $account = $guard === 'admin'
            ? User::factory()->create(['email' => 'missing-version-admin@example.com'])
            : Member::factory()->create(['email' => 'missing-version-member@example.com']);

        $this->assertUnauthenticatedRefresh($guard, $this->tokenWithClaims([
            'sub' => $account->getKey(),
            'guard' => $guard,
            'prv' => sha1($account::class),
        ]));
    }

    #[DataProvider('guardProvider')]
    public function test_token_with_mismatched_authentication_version_cannot_be_refreshed(string $guard): void
    {
        $account = $guard === 'admin'
            ? User::factory()->create(['email' => 'mismatched-version-admin@example.com'])
            : Member::factory()->create(['email' => 'mismatched-version-member@example.com']);

        $this->assertUnauthenticatedRefresh($guard, $this->tokenWithClaims([
            'sub' => $account->getKey(),
            'guard' => $guard,
            'prv' => sha1($account::class),
            'auth_version' => $account->auth_version + 1,
        ]));
    }

    #[DataProvider('invalidTokenProvider')]
    public function test_missing_or_malformed_token_cannot_be_refreshed(?string $token): void
    {
        foreach (['admin', 'member'] as $guard) {
            $headers = $token === null ? [] : $this->authorizationHeader($token);

            $this->postJson($this->refreshUri($guard), headers: $headers)
                ->assertUnauthorized()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 401)
                ->assertJsonPath('message', 'Unauthenticated')
                ->assertHeader('X-Request-Id');
        }
    }

    #[DataProvider('guardProvider')]
    public function test_old_token_cannot_be_refreshed_twice(string $guard): void
    {
        [, $token] = $this->login($guard);

        $this->postJson($this->refreshUri($guard), headers: $this->authorizationHeader($token))
            ->assertOk();

        $this->assertUnauthenticatedRefresh($guard, $token);
    }

    #[DataProvider('guardProvider')]
    public function test_successful_refresh_preserves_claims_and_guard_isolation(string $guard): void
    {
        [$account, $token] = $this->login($guard);
        $this->createOppositeAccount($guard, $account->getKey());

        $expectedProviderClaim = sha1($guard === 'admin' ? User::class : Member::class);
        $loginPayload = $this->payload($token);
        $this->assertSame($guard, $loginPayload['guard'] ?? null);
        $this->assertSame($expectedProviderClaim, $loginPayload['prv'] ?? null);
        $this->assertSame($account->auth_version, $loginPayload['auth_version'] ?? null);

        $refresh = $this->postJson(
            $this->refreshUri($guard),
            headers: $this->authorizationHeader($token),
        )->assertOk();

        $refreshedToken = $refresh->json('data.access_token');
        $this->assertIsString($refreshedToken);

        $refreshPayload = $this->payload($refreshedToken);
        $this->assertSame($guard, $refreshPayload['guard'] ?? null);
        $this->assertSame($expectedProviderClaim, $refreshPayload['prv'] ?? null);
        $this->assertSame((string) $account->getKey(), (string) ($refreshPayload['sub'] ?? ''));
        $this->assertSame($account->auth_version, $refreshPayload['auth_version'] ?? null);

        $this->getJson($this->meUri($guard), $this->authorizationHeader($refreshedToken))
            ->assertOk()
            ->assertJsonPath($this->identityPath($guard), $account->getKey());

        $oppositeGuard = $guard === 'admin' ? 'member' : 'admin';
        $this->getJson($this->meUri($oppositeGuard), $this->authorizationHeader($refreshedToken))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');

        $this->assertUnauthenticatedRefresh($guard, $token);
    }

    #[DataProvider('guardProvider')]
    public function test_successful_refresh_records_the_refreshed_subject(string $guard): void
    {
        [$account, $token] = $this->login($guard);

        $refresh = $this->postJson(
            $this->refreshUri($guard),
            headers: $this->authorizationHeader($token),
        )->assertOk();

        $this->assertDatabaseHas(LoginLog::class, [
            'guard' => $guard,
            'account' => $guard === 'admin' ? $account->email : ($account->email ?? $account->mobile),
            'subject_type' => $account->getMorphClass(),
            'subject_id' => $account->getKey(),
            'event' => 'refresh',
            'successful' => true,
            'request_id' => $refresh->json('request_id'),
        ]);
    }

    public function test_refresh_routes_run_outside_standard_authentication_middleware(): void
    {
        foreach (['admin.auth.refresh' => 'admin', 'member.auth.refresh' => 'member'] as $routeName => $guard) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);

            $middleware = $route->gatherMiddleware();
            $this->assertNotContains('auth:'.$guard, $middleware);
            $this->assertNotContains('account.active:'.$guard, $middleware);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guardProvider(): array
    {
        return [
            'admin' => ['admin'],
            'member' => ['member'],
        ];
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidTokenProvider(): array
    {
        return [
            'missing token' => [null],
            'malformed token' => ['not-a-jwt'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function missingIsolationClaimProvider(): array
    {
        return [
            'admin missing guard' => ['admin', 'guard'],
            'admin missing provider' => ['admin', 'prv'],
            'member missing guard' => ['member', 'guard'],
            'member missing provider' => ['member', 'prv'],
        ];
    }

    /**
     * @return array{Model, string}
     */
    private function login(string $guard, ?int $accountId = null): array
    {
        $identity = $accountId === null ? [] : ['id' => $accountId];

        if ($guard === 'admin') {
            $account = User::factory()->create([
                ...$identity,
                'email' => 'jwt-admin@example.com',
                'password' => 'password',
            ]);
            $response = $this->postJson('/api/admin/auth/login', [
                'email' => $account->email,
                'password' => 'password',
            ])->assertOk();
        } else {
            $account = Member::factory()->create([
                ...$identity,
                'email' => 'jwt-member@example.com',
                'mobile' => '13900000000',
                'password' => 'password',
            ]);
            $response = $this->postJson('/api/auth/login', [
                'account' => $account->email,
                'password' => 'password',
            ])->assertOk();
        }

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return [$account, $token];
    }

    private function createOppositeAccount(string $guard, mixed $id): void
    {
        if ($guard === 'admin') {
            Member::factory()->create([
                'id' => $id,
                'email' => 'jwt-opposite-member@example.com',
            ]);

            return;
        }

        User::factory()->create([
            'id' => $id,
            'email' => 'jwt-opposite-admin@example.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $token): array
    {
        return app(JWT::class)->setToken($token)->getPayload()->toArray();
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function tokenWithClaims(array $claims): string
    {
        $payload = app(Factory::class)
            ->customClaims($claims)
            ->make(true);

        return app(Manager::class)->encode($payload)->get();
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function assertUnauthenticatedRefresh(string $guard, string $token): void
    {
        $this->postJson($this->refreshUri($guard), headers: $this->authorizationHeader($token))
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    private function refreshUri(string $guard): string
    {
        return $guard === 'admin' ? '/api/admin/auth/refresh' : '/api/auth/refresh';
    }

    private function logoutUri(string $guard): string
    {
        return $guard === 'admin' ? '/api/admin/auth/logout' : '/api/auth/logout';
    }

    private function meUri(string $guard): string
    {
        return $guard === 'admin' ? '/api/admin/auth/me' : '/api/auth/me';
    }

    private function identityPath(string $guard): string
    {
        return $guard === 'admin' ? 'data.user.id' : 'data.member.id';
    }
}
