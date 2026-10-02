<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\Manager;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $adminToken = $this->postJson(ApiRouting::path('/admin/auth/login'), [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson(ApiRouting::path('/auth/me'), ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_member_token_cannot_access_admin_routes(): void
    {
        Member::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password',
        ]);

        $memberToken = $this->postJson(ApiRouting::path('/auth/login'), [
            'account' => 'member@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson(ApiRouting::path('/admin/auth/me'), ['Authorization' => 'Bearer '.$memberToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
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

        $adminToken = $this->postJson(ApiRouting::path('/admin/auth/login'), [
            'email' => 'same-id-admin@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $memberToken = $this->postJson(ApiRouting::path('/auth/login'), [
            'account' => 'same-id-member@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson(ApiRouting::path('/admin/auth/me'), ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id);

        $this->getJson(ApiRouting::path('/auth/me'), ['Authorization' => 'Bearer '.$memberToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.id', $member->id);

        $this->getJson(ApiRouting::path('/auth/me'), ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');

        $this->getJson(ApiRouting::path('/admin/auth/me'), ['Authorization' => 'Bearer '.$memberToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_member_guard_permission_record_cannot_satisfy_admin_route_mapping(): void
    {
        Permission::findOrCreate('system.role.view', 'member');

        $adminToken = $this->postJson(ApiRouting::path('/admin/auth/login'), [
            'email' => User::factory()->create(['email' => 'guard-denied@example.com'])->email,
            'password' => 'password',
        ])->json('data.access_token');

        $this->getJson(ApiRouting::path('/admin/roles'), ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertHeader('X-Request-Id');
    }

    #[DataProvider('invalidIsolationClaimProvider')]
    public function test_protected_routes_reject_signed_tokens_without_matching_isolation_claims(string $guard, string $claim, mixed $value): void
    {
        $account = $guard === 'admin' ? User::factory()->create() : Member::factory()->create();
        $claims = [
            'sub' => $account->getKey(),
            'guard' => $guard,
            'prv' => sha1($account::class),
            'auth_version' => $account->auth_version,
        ];

        if ($value === null) {
            unset($claims[$claim]);
        } else {
            $claims[$claim] = $value;
        }

        $payload = app(Factory::class)->customClaims($claims)->make(true);
        $token = app(Manager::class)->encode($payload)->get();
        $path = $guard === 'admin' ? '/admin/auth/me' : '/auth/me';

        $this->getJson(ApiRouting::path($path), ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string, mixed}>
     */
    public static function invalidIsolationClaimProvider(): array
    {
        return [
            'admin missing guard' => ['admin', 'guard', null],
            'member missing guard' => ['member', 'guard', null],
            'admin missing provider' => ['admin', 'prv', null],
            'member missing provider' => ['member', 'prv', null],
            'admin incorrect guard' => ['admin', 'guard', 'member'],
            'member incorrect guard' => ['member', 'guard', 'admin'],
        ];
    }
}
