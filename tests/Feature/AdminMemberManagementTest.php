<?php

namespace Tests\Feature;

use App\Actions\Admin\ManageMember;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminMemberManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    private const PERMISSIONS = [
        'system.member.view',
        'system.member.create',
        'system.member.update',
        'system.member.status',
        'system.member.reset_password',
        'system.member.invalidate_sessions',
    ];

    public function test_admin_can_create_list_filter_search_and_view_members(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $created = $this->postJson('/api/admin/members', [
            'name' => 'Managed Member',
            'email' => ' managed@example.com ',
            'mobile' => '',
            'password' => 'member-password',
            'password_confirmation' => 'member-password',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.member.name', 'Managed Member')
            ->assertJsonPath('data.member.email', 'managed@example.com')
            ->assertJsonPath('data.member.mobile', null)
            ->assertJsonMissingPath('data.member.password')
            ->assertJsonMissingPath('data.member.auth_version')
            ->assertHeader('X-Request-Id');

        $memberId = $created->json('data.member.id');
        $createdActivity = Activity::query()
            ->where('event', 'member_created')
            ->where('subject_id', $memberId)
            ->firstOrFail();
        $this->assertStringNotContainsString('member-password', $createdActivity->properties->toJson());
        Member::factory()->inactive()->create(['name' => 'Other Member']);

        $this->getJson('/api/admin/members?search=Managed&is_active=1&per_page=1', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $memberId)
            ->assertJsonPath('meta.page_size', 1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/members/'.$memberId, $headers)
            ->assertOk()
            ->assertJsonStructure(['data' => ['member' => [
                'id', 'name', 'email', 'mobile', 'is_active', 'last_login_at', 'last_login_ip', 'created_at', 'updated_at',
            ]]])
            ->assertJsonMissingPath('data.member.password')
            ->assertJsonMissingPath('data.member.auth_version');

        $legacyMember = Member::factory()->create(['name' => null]);
        $this->getJson('/api/admin/members/'.$legacyMember->id, $headers)
            ->assertOk()
            ->assertJsonPath('data.member.name', '');
    }

    public function test_member_list_accepts_boolean_query_strings_without_broadening_validation(): void
    {
        $activeMember = Member::factory()->create(['is_active' => true]);
        $inactiveMember = Member::factory()->inactive()->create();
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.member.view']));

        $this->getJson('/api/admin/members?is_active=true', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeMember->id);

        $this->getJson('/api/admin/members?is_active=false', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveMember->id);

        $this->getJson('/api/admin/members?is_active=yes', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    public function test_member_create_and_update_require_unique_retained_identity(): void
    {
        $existing = Member::factory()->create([
            'email' => 'existing@example.com',
            'mobile' => '13800000001',
        ]);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->postJson('/api/admin/members', [
            'name' => 'Missing Identity',
            'password' => 'member-password',
            'password_confirmation' => 'member-password',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile']);

        $this->postJson('/api/admin/members', [
            'name' => 'Blank Identity',
            'email' => '   ',
            'mobile' => "\t",
            'password' => 'member-password',
            'password_confirmation' => 'member-password',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile']);

        $this->postJson('/api/admin/members', [
            'name' => 'Duplicate Identity',
            'email' => $existing->email,
            'password' => 'member-password',
            'password_confirmation' => 'member-password',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->putJson('/api/admin/members/'.$existing->id, [
            'email' => '',
            'mobile' => '',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile']);

        $this->putJson('/api/admin/members/'.$existing->id, [
            'is_active' => false,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->putJson('/api/admin/members/'.$existing->id, [
            'name' => '',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_member_identifiers_reject_email_shaped_mobile_and_cross_column_conflicts(): void
    {
        $existing = Member::factory()->create([
            'email' => 'legacy-account',
            'mobile' => 'legacy-mobile@example.com',
        ]);
        $member = Member::factory()->create([
            'email' => 'member@example.com',
            'mobile' => '13800000001',
        ]);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $password = [
            'password' => 'member-password',
            'password_confirmation' => 'member-password',
        ];

        $emailShapedMobile = $this->postJson('/api/admin/members', [
            'name' => 'Email Shaped Mobile',
            'mobile' => 'mobile@example.com',
            ...$password,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mobile')
            ->assertHeader('X-Request-Id');
        $this->assertSame($emailShapedMobile->json('request_id'), $emailShapedMobile->headers->get('X-Request-Id'));

        $this->postJson('/api/admin/members', [
            'name' => 'Cross Column Email',
            'email' => $existing->mobile,
            ...$password,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/admin/members', [
            'name' => 'Cross Column Mobile',
            'mobile' => $existing->email,
            ...$password,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('mobile');

        $this->putJson('/api/admin/members/'.$member->id, [
            'mobile' => 'updated@example.com',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('mobile');

        $this->putJson('/api/admin/members/'.$member->id, [
            'email' => $existing->mobile,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->putJson('/api/admin/members/'.$member->id, [
            'mobile' => $existing->email,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('mobile');

        $this->putJson('/api/admin/members/'.$member->id, [
            'email' => $member->email,
            'mobile' => $member->mobile,
        ], $headers)->assertOk();
        $this->assertSame(1, $member->refresh()->auth_version);
    }

    public function test_security_operations_invalidate_access_and_refresh_tokens_and_status_is_idempotent(): void
    {
        $member = Member::factory()->create(['email' => 'lifecycle@example.com', 'mobile' => null]);
        $adminHeaders = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $memberToken = $this->memberTokenFor($member, 'password');
        $this->putJson('/api/admin/members/'.$member->id, ['name' => 'Name Only'], $adminHeaders)->assertOk();
        $this->assertSame(1, $member->refresh()->auth_version);
        $this->getJson('/api/auth/me', $this->authorizationHeader($memberToken))->assertOk();

        $this->putJson('/api/admin/members/'.$member->id, ['email' => 'changed@example.com'], $adminHeaders)->assertOk();
        $this->assertSame(2, $member->refresh()->auth_version);
        $this->assertOldMemberTokenIsInvalid($memberToken);

        $memberToken = $this->memberTokenFor($member, 'password');
        $this->putJson('/api/admin/members/'.$member->id.'/status', ['is_active' => false], $adminHeaders)->assertOk();
        $this->assertSame(3, $member->refresh()->auth_version);
        $this->assertOldMemberTokenIsInvalid($memberToken);
        $this->postJson('/api/auth/login', ['account' => $member->email, 'password' => 'password'])->assertUnauthorized();

        $this->putJson('/api/admin/members/'.$member->id.'/status', ['is_active' => true], $adminHeaders)->assertOk();
        $this->assertSame(4, $member->refresh()->auth_version);
        $this->putJson('/api/admin/members/'.$member->id.'/status', ['is_active' => true], $adminHeaders)->assertOk();
        $this->assertSame(4, $member->refresh()->auth_version);
        $this->assertOldMemberTokenIsInvalid($memberToken);

        $memberToken = $this->memberTokenFor($member, 'password');
        $this->putJson('/api/admin/members/'.$member->id.'/password', [
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ], $adminHeaders)->assertOk();
        $this->assertSame(5, $member->refresh()->auth_version);
        $this->assertTrue(Hash::check('replacement-password', $member->password));
        $this->assertOldMemberTokenIsInvalid($memberToken);
        $this->postJson('/api/auth/login', ['account' => $member->email, 'password' => 'password'])->assertUnauthorized();

        $memberToken = $this->memberTokenFor($member, 'replacement-password');
        $this->post('/api/admin/members/'.$member->id.'/invalidate-sessions', [], $adminHeaders)->assertOk();
        $this->assertSame(6, $member->refresh()->auth_version);
        $this->assertOldMemberTokenIsInvalid($memberToken);

        $events = Activity::query()
            ->where('subject_type', $member->getMorphClass())
            ->where('subject_id', $member->id)
            ->pluck('event');

        foreach (['member_updated', 'member_status_updated', 'member_password_reset', 'member_sessions_invalidated'] as $event) {
            $this->assertContains($event, $events);
        }

        Activity::query()
            ->where('subject_type', $member->getMorphClass())
            ->where('subject_id', $member->id)
            ->each(function (Activity $activity) use ($member): void {
                $json = $activity->properties->toJson();
                $this->assertStringNotContainsString('replacement-password', $json);
                $this->assertStringNotContainsString($member->password, $json);
            });
    }

    public function test_locked_update_rechecks_identity_against_the_latest_member_state(): void
    {
        $member = Member::factory()->create([
            'email' => 'concurrent@example.com',
            'mobile' => '13800000001',
        ]);
        $staleMember = Member::query()->findOrFail($member->id);
        $actor = User::factory()->create();
        $manageMember = $this->app->make(ManageMember::class);

        $manageMember->update($member, ['email' => null], $actor);

        try {
            $manageMember->update($staleMember, ['mobile' => null], $actor);
            $this->fail('The locked update must reject removal of the final member identity.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'email' => ['An email address or mobile number is required.'],
                'mobile' => ['An email address or mobile number is required.'],
            ], $exception->errors());
        }

        $member->refresh();
        $this->assertNull($member->email);
        $this->assertSame('13800000001', $member->mobile);
        $this->assertSame(2, $member->auth_version);
    }

    public function test_database_identity_conflicts_are_translated_to_field_validation_errors(): void
    {
        $existing = Member::factory()->create();
        $member = Member::factory()->create();
        $actor = User::factory()->create();
        $manageMember = $this->app->make(ManageMember::class);

        try {
            $manageMember->create([
                'name' => 'Duplicate Email',
                'email' => $existing->email,
                'password' => 'member-password',
            ], $actor);
            $this->fail('Duplicate email should be translated to a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        try {
            $manageMember->update($member, ['mobile' => $existing->mobile], $actor);
            $this->fail('Duplicate mobile should be translated to a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mobile', $exception->errors());
        }
    }

    public function test_each_member_operation_requires_its_exact_permission_and_destroy_is_absent(): void
    {
        $member = Member::factory()->create();
        $user = User::factory()->create();
        $headers = $this->authorizationHeader($this->adminTokenFor($user));
        $cases = [
            ['GET', '/api/admin/members', [], 'system.member.create'],
            ['POST', '/api/admin/members', [], 'system.member.view'],
            ['GET', '/api/admin/members/'.$member->id, [], 'system.member.create'],
            ['PUT', '/api/admin/members/'.$member->id, [], 'system.member.status'],
            ['PUT', '/api/admin/members/'.$member->id.'/status', [], 'system.member.update'],
            ['PUT', '/api/admin/members/'.$member->id.'/password', [], 'system.member.status'],
            ['POST', '/api/admin/members/'.$member->id.'/invalidate-sessions', [], 'system.member.reset_password'],
        ];

        foreach ($cases as [$method, $uri, $payload, $wrongPermission]) {
            $user->syncPermissions([$this->createPermission($wrongPermission)]);
            $this->json($method, $uri, $payload, $headers)->assertForbidden();
        }

        $this->assertNull(Route::getRoutes()->getByName('admin.members.destroy'));
    }

    public function test_invalidate_sessions_distinguishes_query_parameters_from_raw_request_bodies(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.member.invalidate_sessions']));
        $withoutBody = Member::factory()->create();
        $withQuery = Member::factory()->create();

        $this->post('/api/admin/members/'.$withoutBody->id.'/invalidate-sessions', [], $headers)
            ->assertOk();
        $this->post('/api/admin/members/'.$withQuery->id.'/invalidate-sessions?reason=audit', [], $headers)
            ->assertOk();

        foreach (['{}', '[]'] as $body) {
            $member = Member::factory()->create();
            $server = $this->transformHeadersToServerVars(array_merge($headers, [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]));
            $response = $this->call(
                'POST',
                '/api/admin/members/'.$member->id.'/invalidate-sessions',
                server: $server,
                content: $body,
            )->assertUnprocessable()->assertJsonValidationErrors('body')->assertHeader('X-Request-Id');

            $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
            $this->assertSame(1, $member->refresh()->auth_version);
        }
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function memberTokenFor(Member $member, string $password): string
    {
        static $loginSequence = 1;
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.10.0.'.$loginSequence++,
        ])->postJson('/api/auth/login', [
            'account' => $member->email ?? $member->mobile,
            'password' => $password,
        ])->assertOk();
        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    private function assertOldMemberTokenIsInvalid(string $token): void
    {
        $headers = $this->authorizationHeader($token);
        $this->getJson('/api/auth/me', $headers)->assertUnauthorized();
        $this->postJson('/api/auth/refresh', headers: $headers)->assertUnauthorized();
    }
}
