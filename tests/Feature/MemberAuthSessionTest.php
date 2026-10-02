<?php

namespace Tests\Feature;

use App\Actions\Admin\ManageMember;
use App\Models\Member;
use App\Models\MemberAuthSession;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberAuthSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_issues_a_session_and_refresh_preserves_its_sid(): void
    {
        $member = Member::factory()->create();
        $token = $this->login($member);
        $sid = $this->claims($token)['sid'];
        $session = MemberAuthSession::query()->sole();
        $this->assertSame($session->id, $sid);
        $this->assertSame($member->id, $session->member_id);
        $refreshed = $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token))->assertOk()->json('data.access_token');
        $this->assertSame($sid, $this->claims($refreshed)['sid']);
        $this->assertSame(1, MemberAuthSession::query()->count());
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($refreshed))->assertOk();
    }

    public function test_logout_revokes_only_the_current_session_including_its_replacement(): void
    {
        $member = Member::factory()->create();
        $first = $this->login($member);
        $second = $this->login($member);
        $replacement = $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($first))->assertOk()->json('data.access_token');
        $this->postJson(ApiRouting::path('/auth/logout'), headers: $this->headers($replacement))->assertOk();
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($replacement))->assertUnauthorized();
        $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($replacement))->assertUnauthorized();
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($second))->assertOk();
        $this->assertNotNull(MemberAuthSession::query()->findOrFail($this->claims($first, false)['sid'])->revoked_at);
    }

    public function test_logout_all_invalidates_sessions_and_legacy_tokens(): void
    {
        $member = Member::factory()->create();
        $legacy = Auth::guard('member')->login($member);
        $first = $this->login($member);
        $second = $this->login($member);
        $this->deleteJson(ApiRouting::path('/auth/sessions'), headers: $this->headers($first))->assertOk();
        foreach ([$first, $second, $legacy] as $token) {
            $this->getJson(ApiRouting::path('/auth/me'), $this->headers($token))->assertUnauthorized();
            $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token))->assertUnauthorized();
        }
        $this->assertSame(2, $member->refresh()->auth_version);
        $this->assertSame(0, MemberAuthSession::query()->whereNull('revoked_at')->count());
    }

    #[DataProvider('unavailableSessionProvider')]
    public function test_revoked_expired_and_foreign_sessions_cannot_access_or_refresh(string $state): void
    {
        $this->freezeTime();
        $member = Member::factory()->create();
        $token = $this->login($member);
        $session = MemberAuthSession::query()->sole();
        $session->forceFill(match ($state) {
            'revoked' => ['revoked_at' => now()],
            'expired' => ['expires_at' => now()->subSecond()],
            'foreign' => ['member_id' => Member::factory()->create()->id],
        })->save();
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($token))->assertUnauthorized();
        $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token))->assertUnauthorized();
    }

    public static function unavailableSessionProvider(): array
    {
        return [['revoked'], ['expired'], ['foreign']];
    }

    public function test_legacy_tokens_remain_usable_and_adopt_a_session_on_first_refresh(): void
    {
        $member = Member::factory()->create();
        $this->freezeTime();
        $legacy = Auth::guard('member')->login($member);
        $originalRefreshExpiry = now()->addMinutes((int) config('jwt.refresh_ttl'));
        $this->travel(10)->minutes();
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($legacy))->assertOk();
        $this->assertSame(0, MemberAuthSession::query()->count());
        $replacement = $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($legacy))->assertOk()->json('data.access_token');
        $this->assertSame(MemberAuthSession::query()->sole()->id, $this->claims($replacement)['sid']);
        $this->assertSame($originalRefreshExpiry->timestamp, MemberAuthSession::query()->sole()->expires_at->timestamp);
    }

    #[DataProvider('malformedSidProvider')]
    public function test_malformed_sid_is_rejected_instead_of_being_treated_as_a_legacy_token(mixed $sid): void
    {
        $member = Member::factory()->create();
        $token = Auth::guard('member')->claims(['sid' => $sid])->login($member);
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($token))->assertUnauthorized();
        $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token))->assertUnauthorized();
    }

    public static function malformedSidProvider(): array
    {
        return [['invalid'], [null], [123]];
    }

    public function test_admin_invalidation_rejects_a_member_session_token(): void
    {
        $member = Member::factory()->create();
        $token = $this->login($member);
        app(ManageMember::class)->invalidateSessions($member, User::factory()->create());
        $this->getJson(ApiRouting::path('/auth/me'), $this->headers($token))->assertUnauthorized();
        $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token))->assertUnauthorized();
    }

    public function test_password_change_invalidates_every_session(): void
    {
        $member = Member::factory()->create();
        $first = $this->login($member);
        $second = $this->login($member);
        $this->putJson(ApiRouting::path('/auth/password'), [
            'current_password' => 'password', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ], $this->headers($first))->assertOk();
        foreach ([$first, $second] as $token) {
            $this->getJson(ApiRouting::path('/auth/me'), $this->headers($token))->assertUnauthorized();
            $this->postJson(ApiRouting::path('/auth/refresh'), headers: $this->headers($token))->assertUnauthorized();
        }
    }

    public function test_admin_tokens_do_not_require_a_member_session(): void
    {
        $token = Auth::guard('admin')->login(User::factory()->create());
        $this->getJson(ApiRouting::path('/admin/auth/me'), $this->headers($token))->assertOk();
        $this->assertSame(0, MemberAuthSession::query()->count());
    }

    public function test_pruning_removes_inactive_sessions_and_keeps_active_sessions(): void
    {
        $this->freezeTime();
        $active = MemberAuthSession::factory()->create();
        $revoked = MemberAuthSession::factory()->create(['revoked_at' => now()]);
        $expired = MemberAuthSession::factory()->create(['expires_at' => now()->subSecond()]);
        $this->artisan('model:prune', ['--model' => [MemberAuthSession::class]])->assertSuccessful();
        $this->assertModelExists($active);
        $this->assertModelMissing($revoked);
        $this->assertModelMissing($expired);
    }

    private function login(Member $member): string
    {
        return $this->postJson(ApiRouting::path('/auth/login'), [
            'account' => $member->email, 'password' => 'password',
        ])->assertOk()->json('data.access_token');
    }

    private function claims(string $token, bool $checkBlacklist = true): array
    {
        return app(JWT::class)->manager()->decode(new Token($token), $checkBlacklist)->toArray();
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
