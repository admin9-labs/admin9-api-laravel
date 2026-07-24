<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MemberPasswordManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_member_can_change_password_with_current_password_and_old_tokens_are_invalidated(): void
    {
        $member = Member::factory()->create([
            'email' => 'password-change-member@example.com',
        ]);
        $token = $this->memberTokenFor($member);

        $this->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'password changed');

        $this->assertSame(2, $member->refresh()->auth_version);
        $this->assertTrue(Hash::check('new-password', $member->password));

        $this->assertOldTokenIsInvalid($token);

        $this->postJson('/api/auth/login', [
            'account' => $member->email,
            'password' => 'new-password',
        ])->assertOk();

        /** @var Activity $activity */
        $activity = Activity::query()
            ->where('subject_type', $member->getMorphClass())
            ->where('subject_id', $member->id)
            ->where('event', 'password_changed')
            ->latest('id')
            ->firstOrFail();

        $properties = $activity->properties->toArray();

        $this->assertSame('security', $activity->log_name);
        $this->assertSame($member->getMorphClass(), $activity->causer_type);
        $this->assertSame($member->id, $activity->causer_id);
        $this->assertSame('member', $properties['guard']);
        $this->assertSame('member.auth.password.update', $properties['route']);
        $this->assertArrayNotHasKey('password', $properties);
        $this->assertStringNotContainsString('new-password', $activity->properties->toJson());
        $this->assertStringNotContainsString($member->password, $activity->properties->toJson());
    }

    public function test_member_cannot_change_password_with_incorrect_current_password(): void
    {
        $member = Member::factory()->create([
            'email' => 'wrong-current-password-member@example.com',
        ]);
        $token = $this->memberTokenFor($member);

        $this->putJson('/api/auth/password', [
            'current_password' => 'incorrect-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(1, $member->refresh()->auth_version);
        $this->assertTrue(Hash::check('password', $member->password));
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => $member->getMorphClass(),
            'subject_id' => $member->id,
            'event' => 'password_changed',
        ]);
    }

    public function test_member_password_change_enforces_minimum_length_and_confirmation(): void
    {
        $member = Member::factory()->create([
            'email' => 'password-policy-member@example.com',
        ]);
        $token = $this->memberTokenFor($member);

        $this->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'short7',
            'password_confirmation' => 'short7',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'valid-password',
            'password_confirmation' => 'different-password',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(1, $member->refresh()->auth_version);
        $this->assertTrue(Hash::check('password', $member->password));
    }

    private function memberTokenFor(Member $member): string
    {
        $response = $this->postJson('/api/auth/login', [
            'account' => $member->email,
            'password' => 'password',
        ])->assertOk();

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function assertOldTokenIsInvalid(string $token): void
    {
        $headers = $this->authorizationHeader($token);

        $this->getJson('/api/auth/me', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated');

        $this->postJson('/api/auth/refresh', headers: $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated');
    }
}
