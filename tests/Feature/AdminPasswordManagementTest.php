<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminPasswordManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_admin_can_change_password_with_current_password_and_old_tokens_are_invalidated(): void
    {
        $admin = User::factory()->create([
            'email' => 'password-change-admin@example.com',
        ]);
        $token = $this->adminTokenFor($admin);

        $this->putJson('/api/admin/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'password changed');

        $this->assertSame(2, $admin->refresh()->auth_version);
        $this->assertTrue(Hash::check('new-password', $admin->password));

        $this->assertOldTokenIsInvalid($token);

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'new-password',
        ])->assertOk();

        $this->assertSecurityActivity($admin, $admin, 'password_changed', 'admin.auth.password.update', 'new-password');
    }

    public function test_admin_cannot_change_password_with_incorrect_current_password(): void
    {
        $admin = User::factory()->create([
            'email' => 'wrong-current-password-admin@example.com',
        ]);
        $token = $this->adminTokenFor($admin);

        $this->putJson('/api/admin/auth/password', [
            'current_password' => 'incorrect-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(1, $admin->refresh()->auth_version);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => $admin->getMorphClass(),
            'subject_id' => $admin->id,
            'event' => 'password_changed',
        ]);
    }

    public function test_admin_password_change_enforces_minimum_length_and_confirmation(): void
    {
        $admin = User::factory()->create([
            'email' => 'password-policy-admin@example.com',
        ]);
        $token = $this->adminTokenFor($admin);

        $this->putJson('/api/admin/auth/password', [
            'current_password' => 'password',
            'password' => 'short7',
            'password_confirmation' => 'short7',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->putJson('/api/admin/auth/password', [
            'current_password' => 'password',
            'password' => 'valid-password',
            'password_confirmation' => 'different-password',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(1, $admin->refresh()->auth_version);
        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_generic_user_update_rejects_password_and_preserves_authentication_state(): void
    {
        $this->createAdminPermission('system.user.update');

        $actor = User::factory()->create([
            'email' => 'generic-password-update-actor@example.com',
        ]);
        $actor->givePermissionTo('system.user.update');
        $token = $this->adminTokenFor($actor);

        $target = User::factory()->create([
            'email' => 'generic-password-update-target@example.com',
        ]);
        $originalPasswordHash = $target->password;
        $originalAuthenticationVersion = $target->auth_version;

        $this->patchJson('/api/admin/users/'.$target->id, [
            'password' => 'bypass-password',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $target->refresh();

        $this->assertSame($originalPasswordHash, $target->password);
        $this->assertSame($originalAuthenticationVersion, $target->auth_version);
        $this->assertTrue(Hash::check('password', $target->password));
    }

    public function test_authorized_admin_can_reset_another_users_password_and_invalidate_old_tokens(): void
    {
        $this->createAdminPermission('system.user.update');

        $actor = User::factory()->create([
            'email' => 'password-reset-actor@example.com',
        ]);
        $actor->givePermissionTo('system.user.update');
        $actorToken = $this->adminTokenFor($actor);

        $target = User::factory()->create([
            'email' => 'password-reset-target@example.com',
        ]);
        $targetToken = $this->adminTokenFor($target);

        $this->putJson('/api/admin/users/'.$target->id.'/password', [
            'password' => 'reset-password',
            'password_confirmation' => 'reset-password',
        ], $this->authorizationHeader($actorToken))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'password reset');

        $this->assertSame(2, $target->refresh()->auth_version);
        $this->assertTrue(Hash::check('reset-password', $target->password));

        $this->assertOldTokenIsInvalid($targetToken);

        $this->postJson('/api/admin/auth/login', [
            'email' => $target->email,
            'password' => 'reset-password',
        ])->assertOk();

        $this->assertSecurityActivity($target, $actor, 'password_reset', 'admin.users.password.update', 'reset-password');
    }

    public function test_admin_cannot_reset_own_password_through_user_management_endpoint(): void
    {
        $this->createAdminPermission('system.user.update');

        $admin = User::factory()->create([
            'email' => 'self-password-reset-admin@example.com',
        ]);
        $admin->givePermissionTo('system.user.update');
        $token = $this->adminTokenFor($admin);

        $this->putJson('/api/admin/users/'.$admin->id.'/password', [
            'password' => 'reset-password',
            'password_confirmation' => 'reset-password',
        ], $this->authorizationHeader($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertSame(1, $admin->refresh()->auth_version);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => $admin->getMorphClass(),
            'subject_id' => $admin->id,
            'event' => 'password_reset',
        ]);
    }

    public function test_non_super_admin_cannot_reset_reserved_role_holder_passwords(): void
    {
        $this->createAdminPermission('system.user.update');

        $actor = User::factory()->create([
            'email' => 'reserved-password-reset-actor@example.com',
        ]);
        $actor->givePermissionTo('system.user.update');
        $actorToken = $this->adminTokenFor($actor);

        $superAdmin = $this->createSuperAdmin('reserved-password-super-admin@example.com');
        $systemAdmin = User::factory()->create([
            'email' => 'reserved-password-system-admin@example.com',
        ]);
        $systemAdmin->assignRole(Role::findOrCreate('system-admin', 'admin'));

        foreach ([$superAdmin, $systemAdmin] as $target) {
            $originalPasswordHash = $target->password;
            $originalAuthenticationVersion = $target->auth_version;

            $this->putJson('/api/admin/users/'.$target->id.'/password', [
                'password' => 'forbidden-reset-password',
                'password_confirmation' => 'forbidden-reset-password',
            ], $this->authorizationHeader($actorToken))
                ->assertUnprocessable()
                ->assertJsonPath('errors.user.0', 'Only super-admin users may manage accounts with reserved admin roles.');

            $target->refresh();

            $this->assertSame($originalPasswordHash, $target->password);
            $this->assertSame($originalAuthenticationVersion, $target->auth_version);
            $this->assertDatabaseMissing('activity_log', [
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->id,
                'event' => 'password_reset',
            ]);
        }
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

        $this->getJson('/api/admin/auth/me', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated');

        $this->postJson('/api/admin/auth/refresh', headers: $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated');
    }

    private function assertSecurityActivity(
        User $subject,
        User $causer,
        string $event,
        string $route,
        string $password,
    ): void {
        /** @var Activity $activity */
        $activity = Activity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->id)
            ->where('event', $event)
            ->latest('id')
            ->firstOrFail();

        $properties = $activity->properties->toArray();

        $this->assertSame('security', $activity->log_name);
        $this->assertSame($causer->getMorphClass(), $activity->causer_type);
        $this->assertSame($causer->id, $activity->causer_id);
        $this->assertSame('admin', $properties['guard']);
        $this->assertSame($route, $properties['route']);
        $this->assertArrayNotHasKey('password', $properties);
        $this->assertStringNotContainsString($password, $activity->properties->toJson());
        $this->assertStringNotContainsString($subject->password, $activity->properties->toJson());
    }
}
