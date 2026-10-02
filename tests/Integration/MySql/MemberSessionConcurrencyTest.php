<?php

namespace Tests\Integration\MySql;

use App\Models\Member;
use App\Models\MemberAuthSession;
use App\Support\ApiRouting;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\MySqlConcurrencyDatabaseGuard;
use Tests\Support\RunsConcurrentMySqlRequests;
use Tests\TestCase;

#[Group('mysql-concurrency')]
class MemberSessionConcurrencyTest extends TestCase
{
    use InteractsWithAdminRbac, RunsConcurrentMySqlRequests;

    protected function setUp(): void
    {
        parent::setUp();
        MySqlConcurrencyDatabaseGuard::assertSafe();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['cache.default' => 'database', 'cache.prefix' => 'member-session-concurrency-test-']);
        Cache::forgetDriver('database');
        $this->app->forgetInstance('cache.store');
    }

    #[DataProvider('invalidationProvider')]
    public function test_invalidation_serializes_with_refresh_and_rejects_every_replacement(string $cause): void
    {
        for ($round = 0; $round < 3; $round++) {
            $member = Member::factory()->create();
            $source = $this->postJson(ApiRouting::path('/auth/login'), ['account' => $member->email, 'password' => 'password'])
                ->assertOk()->json('data.access_token');
            $invalidation = match ($cause) {
                'logout' => ['method' => 'POST', 'path' => '/auth/logout', 'payload' => [], 'token' => $source],
                'logout-all' => ['method' => 'DELETE', 'path' => '/auth/sessions', 'payload' => [], 'token' => $source],
                'password' => ['method' => 'PUT', 'path' => '/auth/password', 'payload' => [
                    'current_password' => 'password', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
                ], 'token' => $source],
                'admin' => ['method' => 'POST', 'path' => '/admin/members/'.$member->id.'/invalidate-sessions', 'payload' => [],
                    'token' => $this->managerTokenFor(['system.member.invalidate_sessions'])],
            };
            [$refresh, $invalidated] = $this->raceRequests($member, [
                ['method' => 'POST', 'path' => '/auth/refresh', 'payload' => [], 'token' => $source],
                $invalidation,
            ]);

            $this->assertSame(200, $invalidated['status']);
            $this->assertContains($refresh['status'], [200, 401]);
            $tokens = [$source];
            if ($refresh['status'] === 200) {
                $tokens[] = $refresh['body']['data']['access_token'];
            }
            foreach ($tokens as $token) {
                $headers = ['Authorization' => 'Bearer '.$token];
                $this->getJson(ApiRouting::path('/auth/me'), $headers)->assertUnauthorized();
                $this->postJson(ApiRouting::path('/auth/refresh'), headers: $headers)->assertUnauthorized();
            }
        }
    }

    public static function invalidationProvider(): array
    {
        return [['logout'], ['logout-all'], ['password'], ['admin']];
    }

    public function test_pruning_rechecks_expiry_after_a_concurrent_session_extension(): void
    {
        $session = MemberAuthSession::factory()->create(['expires_at' => now()->subMinute()]);
        $request = ['method' => 'GET', 'path' => '/unused', 'payload' => [], 'token' => 'unused', 'prune' => true];

        $results = $this->raceRequests($session, [$request, $request], function () use ($session): void {
            $session->forceFill(['expires_at' => now()->addDay()])->save();
        });

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertModelExists($session);
        $this->assertTrue($session->refresh()->expires_at->isFuture());
    }
}
