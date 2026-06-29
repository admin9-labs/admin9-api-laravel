<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Menu;
use App\Models\User;
use Database\Seeders\AdminRbacSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminRbacTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_admin_rbac_seeder_bootstraps_super_admin_idempotently(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole('super-admin'));
        $this->assertSame(1, User::query()->where('email', 'admin@example.com')->count());
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'admin@example.com');
    }

    public function test_admin_rbac_seeder_does_not_elevate_existing_admin_email(): void
    {
        $existingAdmin = User::factory()->create(['email' => 'admin@example.com']);

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $this->assertSame(1, User::query()->where('email', 'admin@example.com')->count());
        $this->assertFalse($existingAdmin->refresh()->hasRole('super-admin'));
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());
    }

    public function test_admin_rbac_seeder_does_not_create_default_bootstrap_admin_in_production_without_explicit_config(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'admin.bootstrap.email' => null,
            'admin.bootstrap.password' => null,
        ]);

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class, '--force' => true]);

        $this->assertFalse(User::query()->where('email', 'admin@example.com')->exists());
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());
        $this->assertGreaterThan(0, Permission::query()->where('guard_name', 'admin')->where('is_system', true)->count());
    }

    public function test_admin_rbac_seeder_creates_explicit_bootstrap_admin_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'admin.bootstrap.name' => 'Production Root',
            'admin.bootstrap.email' => 'root@example.com',
            'admin.bootstrap.password' => 'explicit-secure-password',
        ]);

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class, '--force' => true]);

        $admin = User::query()->where('email', 'root@example.com')->firstOrFail();

        $this->assertSame('Production Root', $admin->name);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole('super-admin'));
        $this->assertFalse(User::query()->where('email', 'admin@example.com')->exists());

        $this->postJson('/api/admin/auth/login', [
            'email' => 'root@example.com',
            'password' => 'explicit-secure-password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'root@example.com');
    }

    public function test_direct_user_permission_grants_access_to_declared_permission_route(): void
    {
        $permission = $this->createAdminPermission('system.role.view');
        $user = User::factory()->create(['email' => 'direct-rbac@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_role_permission_grants_access_to_declared_permission_route(): void
    {
        $permission = $this->createAdminPermission('system.role.view');
        $role = Role::findOrCreate('role-reader', 'admin');
        $role->givePermissionTo($permission);

        $user = User::factory()->create(['email' => 'role-rbac@example.com']);
        $user->assignRole($role);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_user_without_declared_permission_gets_forbidden(): void
    {
        $this->createAdminPermission('system.role.view');
        $token = $this->adminTokenFor(User::factory()->create(['email' => 'forbidden-rbac@example.com']));

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertHeader('X-Request-Id');
    }

    public function test_super_admin_bypasses_missing_assignment_for_declared_permission_route(): void
    {
        $this->createAdminPermission('system.role.view');
        $user = User::factory()->create(['email' => 'super-rbac@example.com']);
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_member_guard_permission_does_not_authorize_admin_route(): void
    {
        $this->createAdminPermission('system.role.view');
        $this->createAdminPermission('system.role.view', [], 'member');
        $token = $this->adminTokenFor(User::factory()->create(['email' => 'wrong-guard@example.com']));

        $this->getJson('/api/admin/roles', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertHeader('X-Request-Id');
    }

    public function test_member_model_does_not_receive_rbac_methods(): void
    {
        $this->assertFalse(method_exists(Member::factory()->make(), 'assignRole'));
    }

    public function test_seeder_creates_built_in_permissions_with_metadata_and_menu_bindings(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $permission = Permission::query()
            ->where('name', 'system.role.view')
            ->where('guard_name', 'admin')
            ->first();
        $menu = Menu::query()->where('code', 'system.roles')->first();

        $this->assertNotNull($permission);
        $this->assertNotNull($permission->getAttribute('display_name'));
        $this->assertSame('system.role', $permission->getAttribute('group'));
        $this->assertTrue((bool) $permission->getAttribute('is_system'));
        $this->assertTrue((bool) $permission->getAttribute('is_active'));
        $this->assertNotNull($menu);
        $this->assertSame(Menu::TYPE_PAGE, $menu->type);
        $this->assertSame($permission->id, $menu->permission_id);
        $this->assertSame('system.role.view', $menu->permission_name);
    }

    public function test_seeder_creates_complete_built_in_permission_metadata(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $permissions = Permission::query()
            ->where('guard_name', 'admin')
            ->where('is_system', true)
            ->get();

        $this->assertCount(25, $permissions);

        $permissions->each(function (Permission $permission): void {
            $this->assertNotEmpty($permission->getAttribute('display_name'));
            $this->assertNotEmpty($permission->getAttribute('group'));
            $this->assertNotEmpty($permission->getAttribute('description'));
            $this->assertIsInt((int) $permission->getAttribute('sort'));
            $this->assertTrue((bool) $permission->getAttribute('is_active'));
        });
    }

    public function test_seeder_creates_menu_permission_tree_with_buttons(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $system = Menu::query()->where('code', 'system')->firstOrFail();
        $rolePage = Menu::query()->where('code', 'system.roles')->firstOrFail();
        $roleCreate = Menu::query()->where('code', 'system.roles.create')->firstOrFail();
        $assignRole = Menu::query()->where('code', 'system.users.assign-role')->firstOrFail();

        $this->assertSame(Menu::TYPE_DIRECTORY, $system->type);
        $this->assertSame(Menu::TYPE_PAGE, $rolePage->type);
        $this->assertSame(Menu::TYPE_BUTTON, $roleCreate->type);
        $this->assertSame(Menu::TYPE_BUTTON, $assignRole->type);
        $this->assertSame($rolePage->id, $roleCreate->parent_id);
        $this->assertSame('system.role.create', $roleCreate->permission_name);
        $this->assertSame('system.user.assign-role', $assignRole->permission_name);
        $this->assertFalse($roleCreate->is_visible);
    }

    public function test_permission_managed_admin_routes_declare_existing_seed_permission_names(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $seededPermissionNames = Permission::query()
            ->where('guard_name', 'admin')
            ->pluck('name')
            ->all();

        $this->managedAdminPermissionNames()->each(function (string $permissionName) use ($seededPermissionNames): void {
            $this->assertContains($permissionName, $seededPermissionNames);
        });
    }

    public function test_permission_managed_admin_routes_are_covered_by_seeded_menu_permission_tree(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $routePermissionNames = $this->managedAdminPermissionNames()->all();
        $menuPermissionNames = Menu::query()
            ->whereNotNull('permission_name')
            ->orderBy('permission_name')
            ->pluck('permission_name')
            ->all();

        $this->assertSame($routePermissionNames, $menuPermissionNames);

        Menu::query()
            ->whereNotNull('permission_name')
            ->get()
            ->each(function (Menu $menu): void {
                $this->assertNotNull($menu->permission_id);

                if (str_ends_with((string) $menu->permission_name, '.view')) {
                    $this->assertSame(Menu::TYPE_PAGE, $menu->type);

                    return;
                }

                $this->assertSame(Menu::TYPE_BUTTON, $menu->type);
                $this->assertFalse($menu->is_visible);
            });
    }

    public function test_seeder_creates_super_admin_and_system_admin_roles(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());
        $this->assertSame(1, Role::query()->where('name', 'system-admin')->where('guard_name', 'admin')->count());
    }

    public function test_seeder_syncs_system_admin_to_built_in_permissions(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $systemAdmin = Role::query()->where('name', 'system-admin')->where('guard_name', 'admin')->firstOrFail();
        $builtInPermissionCount = Permission::query()
            ->where('guard_name', 'admin')
            ->where('is_system', true)
            ->count();

        $this->assertGreaterThan(0, $builtInPermissionCount);
        $this->assertSame($builtInPermissionCount, $systemAdmin->permissions()->count());
    }

    public function test_seeder_preserves_dynamic_permissions(): void
    {
        $permission = $this->createAdminPermission('dynamic.preserved');

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $this->assertDatabaseHas('permissions', [
            'id' => $permission->id,
            'name' => 'dynamic.preserved',
            'guard_name' => 'admin',
        ]);
    }

    public function test_seeder_creates_missing_bootstrap_admin_once_and_assigns_super_admin(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame(1, User::query()->where('email', 'admin@example.com')->count());
        $this->assertTrue($admin->hasRole('super-admin'));
    }

    /**
     * @return Collection<int, string>
     */
    private function managedAdminPermissionNames(): Collection
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'admin.'))
            ->flatMap(fn (Route $route): array => $route->gatherMiddleware())
            ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'))
            ->map(fn (string $middleware): string => str($middleware)->after('permission:')->before(',')->toString())
            ->unique()
            ->sort()
            ->values();
    }
}
