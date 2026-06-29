<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminPermissionMiddlewareTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_protected_admin_routes_use_explicit_spatie_permission_middleware(): void
    {
        $this->permissionProtectedAdminRoutes()->each(function (Route $route): void {
            $middleware = collect($route->gatherMiddleware());

            $this->assertTrue(
                $middleware->contains(fn (string $entry): bool => str_starts_with($entry, 'permission:') && str_ends_with($entry, ',admin')),
                sprintf('Route [%s] must include explicit permission:<name>,admin middleware.', $route->getName())
            );
        });
    }

    public function test_auth_only_admin_routes_remain_without_permission_middleware(): void
    {
        foreach (['admin.auth.me', 'admin.auth.refresh', 'admin.auth.logout', 'admin.menus.tree'] as $routeName) {
            $route = RouteFacade::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);

            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:admin', $middleware);
            $this->assertContains('account.active:admin', $middleware);
            $this->assertNotContains('admin.permission', $middleware);
            $this->assertFalse(
                collect($middleware)->contains(fn (string $entry): bool => str_starts_with($entry, 'permission:')),
                sprintf('Route [%s] must not require permission middleware.', $routeName)
            );
        }
    }

    public function test_login_route_remains_outside_admin_auth_and_permission_middleware(): void
    {
        $route = RouteFacade::getRoutes()->getByName('admin.auth.login');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();

        $this->assertContains('throttle:5,1', $middleware);
        $this->assertNotContains('auth:admin', $middleware);
        $this->assertNotContains('account.active:admin', $middleware);
        $this->assertNotContains('admin.permission', $middleware);
        $this->assertFalse(
            collect($middleware)->contains(fn (string $entry): bool => str_starts_with($entry, 'permission:'))
        );
    }

    public function test_auth_only_routes_are_accessible_to_authenticated_admins_without_permission_assignment(): void
    {
        $token = $this->adminTokenFor(User::factory()->create(['email' => 'auth-only@example.com']));

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $refreshToken = $this->postJson('/api/admin/auth/refresh', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.access_token');
        $this->assertIsString($refreshToken);

        $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$refreshToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/admin/auth/logout', [], ['Authorization' => 'Bearer '.$refreshToken])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_required_permission_allows_direct_assignment_and_denies_missing_assignment(): void
    {
        $this->createAdminPermission('system.role.view');
        $allowed = User::factory()->create(['email' => 'direct-allow@example.com']);
        $allowed->givePermissionTo('system.role.view');
        $allowedToken = $this->adminTokenFor($allowed);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$allowedToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $deniedToken = $this->adminTokenFor(User::factory()->create(['email' => 'direct-denied@example.com']));

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$deniedToken])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    public function test_required_permission_allows_role_assignment(): void
    {
        $permission = $this->createAdminPermission('system.role.view');
        $role = Role::findOrCreate('role-reader', 'admin');
        $role->givePermissionTo($permission);

        $user = User::factory()->create(['email' => 'role-allow@example.com']);
        $user->assignRole($role);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_inactive_permission_denies_regular_admin_even_when_assigned(): void
    {
        $permission = $this->createAdminPermission('system.role.view', ['is_active' => false]);
        $user = User::factory()->create(['email' => 'inactive-permission@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    public function test_super_admin_bypasses_permission_assignment_but_not_inactive_permission_definition(): void
    {
        $this->createAdminPermission('system.role.view');
        $token = $this->adminTokenFor($this->createSuperAdmin('super-permission-bypass@example.com'));

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->createAdminPermission('system.menu.update', ['is_active' => false]);

        $root = Menu::factory()->create(['code' => 'business.root']);
        $child = Menu::factory()->create(['parent_id' => $root->id, 'code' => 'business.child']);
        $grandchild = Menu::factory()->create(['parent_id' => $child->id, 'code' => 'business.grandchild']);

        $this->patchJson('/api/admin/menus/'.$root->id, [
            'parent_id' => $grandchild->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    public function test_super_admin_cannot_bypass_business_form_request_denial_for_active_permission(): void
    {
        $this->createAdminPermission('system.menu.update');

        $root = Menu::factory()->create(['code' => 'business.active.root']);
        $child = Menu::factory()->create(['parent_id' => $root->id, 'code' => 'business.active.child']);
        $grandchild = Menu::factory()->create(['parent_id' => $child->id, 'code' => 'business.active.grandchild']);
        $token = $this->adminTokenFor($this->createSuperAdmin('business-super@example.com'));

        $this->patchJson('/api/admin/menus/'.$root->id, [
            'parent_id' => $grandchild->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertNull($root->refresh()->parent_id);
    }

    public function test_member_guard_role_or_permission_does_not_authorize_admin_route(): void
    {
        $this->createAdminPermission('system.role.view');
        $user = User::factory()->create(['email' => 'member-guard-denied@example.com']);
        $memberPermission = $this->createAdminPermission('system.role.view', [], 'member');
        DB::table('model_has_permissions')->insert([
            'permission_id' => $memberPermission->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ]);
        $memberRole = Role::findOrCreate('super-admin', 'member');
        DB::table('model_has_roles')->insert([
            'role_id' => $memberRole->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ]);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    /**
     * @return Collection<int, Route>
     */
    private function permissionProtectedAdminRoutes(): Collection
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'admin.'))
            ->filter(fn (Route $route): bool => collect($route->gatherMiddleware())
                ->contains(fn (string $entry): bool => str_starts_with($entry, 'permission:')))
            ->values();
    }
}
