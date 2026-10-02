<?php

namespace Tests\Feature;

use App\Actions\Admin\ManageMember;
use App\Models\Member;
use App\Models\MemberAuthSession;
use App\Models\MemberTokenRefresh;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MemberRefreshRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lost_response_retry_returns_the_same_token_with_its_remaining_lifetime(): void
    {
        $this->freezeTime();
        [$member, $source] = $this->login();
        $first = $this->refreshToken($source)->assertOk();
        $token = $first->json('data.access_token');
        $this->travel(5)->seconds();
        $this->refreshToken($source)->assertOk()->assertJsonPath('data.access_token', $token)
            ->assertJsonPath('data.expires_in', $first->json('data.expires_in') - 5);
        $this->assertSame(1, MemberTokenRefresh::query()->count());
        $record = MemberTokenRefresh::query()->sole();
        $this->assertNotSame($token, DB::table('member_token_refreshes')->value('token'));
        $this->assertArrayNotHasKey('token', $record->toArray());
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($source))->assertUnauthorized();
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($token))->assertOk();
    }

    public function test_retries_do_not_extend_the_recovery_window_or_sliding_session_expiry(): void
    {
        $this->freezeTime();
        config(['jwt.refresh_iat' => true]);
        [, $source] = $this->login();
        $this->travel(10)->seconds();
        $this->refreshToken($source)->assertOk();
        $sessionExpiry = MemberAuthSession::query()->sole()->expires_at->timestamp;
        $recoveryExpiry = MemberTokenRefresh::query()->sole()->expires_at->timestamp;
        $this->travel(20)->seconds();
        $this->refreshToken($source)->assertOk();
        $this->assertSame($sessionExpiry, MemberAuthSession::query()->sole()->expires_at->timestamp);
        $this->assertSame($recoveryExpiry, MemberTokenRefresh::query()->sole()->expires_at->timestamp);
        $this->travel(11)->seconds();
        $this->refreshToken($source)->assertUnauthorized();
    }

    public function test_refresh_recovers_after_blacklisting_succeeds_but_completion_recording_fails(): void
    {
        [, $source] = $this->login();
        $throwOnce = true;
        MemberTokenRefresh::updating(function (MemberTokenRefresh $record) use (&$throwOnce): void {
            if ($throwOnce && $record->isDirty('blacklisted_at')) {
                $throwOnce = false;
                throw new RuntimeException('Completion interrupted.');
            }
        });
        $this->refreshToken($source)->assertInternalServerError();
        $record = MemberTokenRefresh::query()->sole();
        $this->assertNull($record->blacklisted_at);
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($source))->assertUnauthorized();
        $this->refreshToken($source)->assertOk()->assertJsonPath('data.access_token', $record->token);
        $this->assertNotNull($record->refresh()->blacklisted_at);
    }

    public function test_failed_result_persistence_does_not_consume_the_source(): void
    {
        [, $source] = $this->login();
        $throwOnce = true;
        MemberTokenRefresh::creating(function () use (&$throwOnce): void {
            if ($throwOnce) {
                $throwOnce = false;
                throw new RuntimeException('Persistence interrupted.');
            }
        });
        $this->refreshToken($source)->assertInternalServerError();
        $this->assertSame(0, MemberTokenRefresh::query()->count());
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($source))->assertOk();
        $this->refreshToken($source)->assertOk();
        $this->assertSame(1, MemberTokenRefresh::query()->count());
    }

    #[DataProvider('invalidationProvider')]
    public function test_invalidated_accounts_sessions_and_credentials_cannot_recover(string $cause): void
    {
        $this->freezeTime();
        [$member, $source] = $this->login();
        $successor = $this->refreshToken($source)->assertOk()->json('data.access_token');
        match ($cause) {
            'logout' => $this->postJson(ApiRouting::path('/auth/logout'), headers: $this->headers($successor))->assertOk(),
            'logout-all' => $this->deleteJson(ApiRouting::path('/auth/sessions'), headers: $this->headers($successor))->assertOk(),
            'password' => $this->putJson(ApiRouting::path('/auth/password'), [
                'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
            ], $this->headers($successor))->assertOk(),
            'admin' => app(ManageMember::class)->invalidateSessions($member, User::factory()->create()),
            'revoked' => MemberAuthSession::query()->sole()->forceFill(['revoked_at' => now()])->save(),
            'expired' => MemberAuthSession::query()->sole()->forceFill(['expires_at' => now()->subSecond()])->save(),
            'disabled' => $member->forceFill(['is_active' => false])->save(),
        };
        $response = $this->refreshToken($source);
        if ($cause === 'disabled') {
            $response->assertForbidden();
        } else {
            $response->assertUnauthorized();
        }
    }

    public static function invalidationProvider(): array
    {
        return [['logout'], ['logout-all'], ['password'], ['admin'], ['revoked'], ['expired'], ['disabled']];
    }

    public function test_rotating_the_successor_disables_recovery_from_its_predecessor(): void
    {
        [, $source] = $this->login();
        $successor = $this->refreshToken($source)->assertOk()->json('data.access_token');
        $this->refreshToken($successor)->assertOk();
        $this->refreshToken($source)->assertUnauthorized();
    }

    public function test_predecessor_recovery_stops_even_if_successor_rotation_completion_is_interrupted(): void
    {
        [, $source] = $this->login();
        $successor = $this->refreshToken($source)->assertOk()->json('data.access_token');
        $throwOnce = true;
        MemberTokenRefresh::updating(function (MemberTokenRefresh $record) use (&$throwOnce): void {
            if ($throwOnce && $record->isDirty('blacklisted_at')) {
                $throwOnce = false;
                throw new RuntimeException('Successor rotation interrupted.');
            }
        });
        $this->refreshToken($successor)->assertInternalServerError();
        $this->refreshToken($source)->assertUnauthorized();
        $this->refreshToken($successor)->assertOk();
    }

    public function test_pruning_expired_results_does_not_make_the_blacklisted_source_reusable(): void
    {
        $this->freezeTime();
        [, $source] = $this->login();
        $successor = $this->refreshToken($source)->assertOk()->json('data.access_token');
        $this->travel(MemberTokenRefresh::RECOVERY_SECONDS + 1)->seconds();
        $this->artisan('model:prune', ['--model' => [MemberTokenRefresh::class]])->assertSuccessful();
        $this->assertSame(0, MemberTokenRefresh::query()->count());
        $this->refreshToken($source)->assertUnauthorized();
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($successor))->assertOk();
    }

    #[DataProvider('successorMismatchProvider')]
    public function test_recovery_rejects_successors_with_wrong_identity_guard_version_or_session(string $mismatch): void
    {
        [$member, $source] = $this->login();
        $this->refreshToken($source)->assertOk();
        $record = MemberTokenRefresh::query()->sole();
        $guard = $mismatch === 'guard' ? 'admin' : 'member';
        $account = $guard === 'admin' ? User::factory()->create() : ($mismatch === 'identity' ? Member::factory()->create() : $member);
        $claims = ['sid' => $record->member_auth_session_id];
        if ($mismatch === 'session') {
            $claims['sid'] = MemberAuthSession::factory()->create(['member_id' => $member->id])->id;
        }
        $token = Auth::guard($guard)->claims($claims)->login($account);
        if ($mismatch === 'version' || $mismatch === 'provider') {
            $payload = app(JWT::class)->manager()->decode(new Token($token))->toArray();
            unset($payload['jti']);
            $payload[$mismatch === 'version' ? 'auth_version' : 'prv'] = $mismatch === 'version' ? 999 : sha1(User::class);
            $token = app(JWT::class)->manager()->encode(app(JWT::class)->factory()->customClaims($payload)->make(true))->get();
        }
        $record->forceFill(['token' => $token])->save();
        $this->refreshToken($source)->assertUnauthorized();
    }

    public static function successorMismatchProvider(): array
    {
        return [['identity'], ['guard'], ['version'], ['session'], ['provider']];
    }

    public function test_legacy_refresh_retries_adopt_only_one_session(): void
    {
        $member = Member::factory()->create();
        $legacy = Auth::guard('member')->login($member);
        $first = $this->refreshToken($legacy)->assertOk()->json('data.access_token');
        $this->refreshToken($legacy)->assertOk()->assertJsonPath('data.access_token', $first);
        $this->assertSame(1, MemberAuthSession::query()->count());
        $this->assertSame(1, MemberTokenRefresh::query()->count());
    }

    public function test_expired_successors_are_not_returned_even_within_the_recovery_window(): void
    {
        $this->freezeTime();
        [, $source] = $this->login();
        $successor = $this->refreshToken($source)->assertOk()->json('data.access_token');
        $jwt = app(JWT::class);
        $claims = $jwt->manager()->decode(new Token($successor))->toArray();
        $claims['exp'] = now()->timestamp + 1;
        $shortLived = $jwt->manager()->encode($jwt->factory()->customClaims($claims)->make(true))->get();
        MemberTokenRefresh::query()->sole()->forceFill(['token' => $shortLived])->save();
        $this->travel(2)->seconds();
        $this->refreshToken($source)->assertUnauthorized();
    }

    #[DataProvider('cacheFailureProvider')]
    public function test_blacklist_failure_is_retryable_and_recovers_the_prepared_result(bool $throws): void
    {
        $this->failNextRevocationWrite($throws);
        [, $source] = $this->login();
        $this->refreshToken($source)->assertServiceUnavailable()->assertHeader('Retry-After', '1');
        $prepared = MemberTokenRefresh::query()->sole();
        $this->assertNull($prepared->blacklisted_at);
        $this->refreshToken($source)->assertOk()->assertJsonPath('data.access_token', $prepared->token);
        $this->assertNotNull($prepared->refresh()->blacklisted_at);
    }

    public static function cacheFailureProvider(): array
    {
        return ['exception' => [true], 'silent write failure' => [false]];
    }

    public function test_legacy_logout_revokes_a_session_adopted_before_blacklist_failure(): void
    {
        $this->failNextRevocationWrite();
        $source = Auth::guard('member')->login(Member::factory()->create());
        $this->refreshToken($source)->assertServiceUnavailable();
        $session = MemberAuthSession::query()->sole();
        $this->postJson(ApiRouting::path('/auth/logout'), headers: $this->headers($source))->assertOk();
        $this->assertNotNull($session->refresh()->revoked_at);
        $this->refreshToken($source)->assertUnauthorized();
    }

    private function failNextRevocationWrite(bool $throws = true): void
    {
        $store = new class($throws) extends ArrayStore
        {
            private bool $failOnce = true;

            public function __construct(private bool $throws)
            {
                parent::__construct();
            }

            public function put(mixed $key, mixed $value, mixed $seconds): bool
            {
                if ($this->failOnce && is_array($value) && array_key_exists('valid_until', $value)) {
                    $this->failOnce = false;
                    if ($this->throws) {
                        throw new RuntimeException('Blacklist storage unavailable.');
                    }

                    return false;
                }

                return parent::put($key, $value, $seconds);
            }
        };
        Cache::getFacadeRoot()->extend('fail-once', fn (): Repository => new Repository($store));
        config(['cache.default' => 'fail-once', 'cache.stores.fail-once' => ['driver' => 'fail-once']]);
        $this->app->forgetInstance('cache.store');
    }

    private function login(): array
    {
        $member = Member::factory()->create();
        $token = $this->postJson(ApiRouting::path('/auth/login'), ['account' => $member->email, 'password' => 'password'])
            ->assertOk()->json('data.access_token');

        return [$member, $token];
    }

    private function refreshToken(string $token): TestResponse
    {
        return $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token));
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
