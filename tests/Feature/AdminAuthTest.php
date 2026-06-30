<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_composer_setup_generates_jwt_secret_before_migrations(): void
    {
        /** @var array{scripts: array{setup: array<int, string>}} $composer */
        $composer = json_decode((string) file_get_contents(getcwd().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $setup = $composer['scripts']['setup'];

        $jwtSecretIndex = array_search('@php artisan jwt:secret --always-no', $setup, true);
        $keyGenerateIndex = array_search('@php artisan key:generate', $setup, true);
        $migrateIndex = array_search('@php artisan migrate --force', $setup, true);

        $this->assertIsInt($jwtSecretIndex);
        $this->assertIsInt($keyGenerateIndex);
        $this->assertIsInt($migrateIndex);
        $this->assertGreaterThan($keyGenerateIndex, $jwtSecretIndex);
        $this->assertLessThan($migrateIndex, $jwtSecretIndex);
    }

    public function test_admin_can_login_view_refresh_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $login = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'admin@example.com')
            ->assertJsonPath('data.permission_names', [])
            ->assertJsonMissingPath('data.user.password')
            ->assertHeader('X-Request-Id');

        $token = $login->json('data.access_token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'admin@example.com')
            ->assertJsonPath('data.permission_names', [])
            ->assertHeader('X-Request-Id');

        $refresh = $this->postJson('/api/admin/auth/refresh', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.permission_names', [])
            ->assertHeader('X-Request-Id');

        $refreshedToken = $refresh->json('data.access_token');
        $this->assertIsString($refreshedToken);
        $this->assertNotSame($token, $refreshedToken);

        $this->postJson('/api/admin/auth/logout', [], ['Authorization' => 'Bearer '.$refreshedToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'logged out')
            ->assertHeader('X-Request-Id');

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$refreshedToken])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_admin_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertHeader('X-Request-Id');
    }

    public function test_admin_login_and_existing_tokens_reject_disabled_accounts(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->json('data.access_token');

        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('message', 'Account disabled')
            ->assertHeader('X-Request-Id');

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('message', 'Account disabled')
            ->assertHeader('X-Request-Id');
    }

    public function test_admin_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_admin_identity_exposes_active_permission_names_for_frontend_controls(): void
    {
        $activePermission = $this->createAdminPermission('system.frontend.active');
        $inactivePermission = $this->createAdminPermission('system.frontend.inactive', ['is_active' => false]);
        $rolePermission = $this->createAdminPermission('system.frontend.role');
        $role = Role::findOrCreate('frontend-control-role', 'admin');
        $role->givePermissionTo($rolePermission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create([
            'email' => 'frontend-controls@example.com',
            'password' => 'password',
        ]);
        $user->givePermissionTo([$activePermission, $inactivePermission]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $login = $this->postJson('/api/admin/auth/login', [
            'email' => 'frontend-controls@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame([
            'system.frontend.active',
            'system.frontend.role',
        ], $login->json('data.permission_names'));

        $token = $login->json('data.access_token');
        $this->assertIsString($token);

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.permission_names', [
                'system.frontend.active',
                'system.frontend.role',
            ]);
    }

    public function test_super_admin_identity_exposes_all_active_admin_permissions_for_frontend_controls(): void
    {
        $this->createAdminPermission('system.frontend.alpha');
        $this->createAdminPermission('system.frontend.beta');
        $this->createAdminPermission('system.frontend.disabled', ['is_active' => false]);

        $user = User::factory()->create([
            'email' => 'frontend-super@example.com',
            'password' => 'password',
        ]);
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $login = $this->postJson('/api/admin/auth/login', [
            'email' => 'frontend-super@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $permissionNames = $login->json('data.permission_names');
        $this->assertContains('system.frontend.alpha', $permissionNames);
        $this->assertContains('system.frontend.beta', $permissionNames);
        $this->assertNotContains('system.frontend.disabled', $permissionNames);
    }
}
