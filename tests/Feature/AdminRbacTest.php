<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Menu;
use App\Models\User;
use App\Support\ApiRouting;
use Database\Seeders\AdminRbacSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
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

        $admin = User::query()->where('email', 'admin@admin9.dev')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole('super-admin'));
        $this->assertSame(1, User::query()->where('email', 'admin@admin9.dev')->count());
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());

        $this->postJson(ApiRouting::path('/admin/auth/login'), [
            'email' => 'admin@admin9.dev',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'admin@admin9.dev');
    }

    public function test_admin_rbac_seeder_does_not_elevate_existing_admin_email(): void
    {
        $existingAdmin = User::factory()->create(['email' => 'admin@admin9.dev']);

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $this->assertSame(1, User::query()->where('email', 'admin@admin9.dev')->count());
        $this->assertFalse($existingAdmin->refresh()->hasRole('super-admin'));
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());
    }

    public function test_admin_rbac_seeder_does_not_create_default_bootstrap_admin_outside_local_or_testing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class, '--force' => true]);

        $this->assertFalse(User::query()->where('email', 'admin@admin9.dev')->exists());
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());
        $this->assertGreaterThan(0, Permission::query()->where('guard_name', 'admin')->where('is_system', true)->count());
    }

    public function test_admin_rbac_seeder_does_not_create_an_admin_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class, '--force' => true]);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(1, Role::query()->where('name', 'super-admin')->where('guard_name', 'admin')->count());
        $this->assertGreaterThan(0, Permission::query()->where('guard_name', 'admin')->where('is_system', true)->count());
    }

    public function test_direct_user_permission_grants_access_to_declared_permission_route(): void
    {
        $permission = $this->createAdminPermission('system.role.view');
        $user = User::factory()->create(['email' => 'direct-rbac@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson(ApiRouting::path('/admin/roles'), ['Authorization' => 'Bearer '.$token])
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

        $this->getJson(ApiRouting::path('/admin/roles'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_user_without_declared_permission_gets_forbidden(): void
    {
        $this->createAdminPermission('system.role.view');
        $token = $this->adminTokenFor(User::factory()->create(['email' => 'forbidden-rbac@example.com']));

        $this->getJson(ApiRouting::path('/admin/roles'), ['Authorization' => 'Bearer '.$token])
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

        $this->getJson(ApiRouting::path('/admin/roles'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_member_guard_permission_does_not_authorize_admin_route(): void
    {
        $this->createAdminPermission('system.role.view');
        $this->createAdminPermission('system.role.view', [], 'member');
        $token = $this->adminTokenFor(User::factory()->create(['email' => 'wrong-guard@example.com']));

        $this->getJson(ApiRouting::path('/admin/roles'), ['Authorization' => 'Bearer '.$token])
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
        $this->assertSame(['system.role.view'], $menu->permissions()->pluck('name')->all());
    }

    public function test_seeder_creates_complete_built_in_permission_metadata(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $permissions = Permission::query()
            ->where('guard_name', 'admin')
            ->where('is_system', true)
            ->get();

        $this->assertCount(36, $permissions);
        $this->assertContains('system.activity-log.view', $permissions->pluck('name'));
        $this->assertContains('system.login-log.view', $permissions->pluck('name'));
        $this->assertContains('system.member.invalidate_sessions', $permissions->pluck('name'));
        $this->assertContains('system.file.delete', $permissions->pluck('name'));

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
        $memberPage = Menu::query()->where('code', 'SystemMember')->firstOrFail();
        $filePage = Menu::query()->where('code', 'system.file')->firstOrFail();
        $systemSettingsPage = Menu::query()->where('code', 'system.configs')->firstOrFail();
        $systemSettingsUpdate = Menu::query()->where('code', 'system.configs.update')->firstOrFail();
        $logs = Menu::query()->where('code', 'system.logs')->firstOrFail();

        $this->assertSame(Menu::TYPE_DIRECTORY, $system->type);
        $this->assertSame(Menu::TYPE_PAGE, $rolePage->type);
        $this->assertSame(Menu::TYPE_BUTTON, $roleCreate->type);
        $this->assertSame(Menu::TYPE_BUTTON, $assignRole->type);
        $this->assertSame($rolePage->id, $roleCreate->parent_id);
        $this->assertSame(['system.role.create'], $roleCreate->permissions()->pluck('name')->all());
        $this->assertSame(['system.user.assign-role'], $assignRole->permissions()->pluck('name')->all());
        $this->assertSame('SystemMember', $memberPage->code);
        $this->assertSame('/system/members', $memberPage->path);
        $this->assertSame(['system.member.view'], $memberPage->permissions()->pluck('name')->all());
        $this->assertEqualsCanonicalizing([
            'system.member.create',
            'system.member.update',
            'system.member.status',
            'system.member.reset_password',
            'system.member.invalidate_sessions',
        ], $memberPage->children()->with('permissions')->get()->flatMap->permissions->pluck('name')->all());
        $this->assertFalse(Menu::query()->where('code', 'SystemMember.delete')->exists());
        $this->assertSame(Menu::TYPE_PAGE, $filePage->type);
        $this->assertSame('/system/file', $filePage->path);
        $this->assertSame(['system.file.view'], $filePage->permissions()->pluck('name')->all());
        $this->assertEqualsCanonicalizing([
            'system.file.create',
            'system.file.delete',
        ], $filePage->children()->with('permissions')->get()->flatMap->permissions->pluck('name')->all());
        $this->assertSame('系统设置', $systemSettingsPage->name);
        $this->assertSame(Menu::TYPE_PAGE, $systemSettingsPage->type);
        $this->assertSame(['system.config.view'], $systemSettingsPage->permissions()->pluck('name')->all());
        $this->assertSame(['system.configs.update'], $systemSettingsPage->children()->pluck('code')->all());
        $this->assertSame(Menu::TYPE_BUTTON, $systemSettingsUpdate->type);
        $this->assertSame(['system.config.update'], $systemSettingsUpdate->permissions()->pluck('name')->all());
        $this->assertFalse(Menu::query()->whereIn('code', ['system.configs.create', 'system.configs.delete'])->exists());
        $this->assertFalse($roleCreate->is_visible);
        $this->assertFalse(Menu::query()->where('code', 'system.activity-logs')->exists());
        $this->assertFalse(Menu::query()->where('code', 'system.login-logs')->exists());
        $this->assertSame($system->id, $logs->parent_id);
        $this->assertSame('/system/log', $logs->path);
        $this->assertSame('system/log/index', $logs->component);
        $this->assertSame(Menu::TYPE_PAGE, $logs->type);
        $this->assertSame(70, $logs->sort);
        $this->assertEqualsCanonicalizing([
            'system.activity-log.view',
            'system.login-log.view',
        ], $logs->permissions()->pluck('name')->all());
    }

    public function test_seeder_repeatedly_removes_obsolete_system_settings_buttons_and_bindings(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $systemSettingsPage = Menu::query()->where('code', 'system.configs')->firstOrFail();
        $role = Role::query()->where('name', 'system-admin')->where('guard_name', 'admin')->firstOrFail();

        Schema::create('role_menu', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $table->primary(['role_id', 'menu_id']);
        });

        try {
            $obsoleteMenuIds = collect(['create', 'delete'])->map(function (string $action) use ($role, $systemSettingsPage): int {
                $menu = Menu::query()->create([
                    'parent_id' => $systemSettingsPage->id,
                    'name' => $action === 'create' ? '新增' : '删除',
                    'code' => "system.configs.{$action}",
                    'type' => Menu::TYPE_BUTTON,
                    'sort' => $action === 'create' ? 10 : 30,
                    'is_visible' => false,
                    'is_active' => true,
                ]);
                $permission = Permission::findByName("system.config.{$action}", 'admin');
                $menu->permissions()->attach($permission);
                DB::table('role_menu')->insert(['role_id' => $role->id, 'menu_id' => $menu->id]);

                return $menu->id;
            });

            Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);
            Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

            $systemSettingsPage->refresh();

            $this->assertSame('系统设置', $systemSettingsPage->name);
            $this->assertSame(['system.configs.update'], $systemSettingsPage->children()->pluck('code')->all());
            $this->assertSame(['system.config.update'], $systemSettingsPage->children()->firstOrFail()->permissions()->pluck('name')->all());
            $this->assertSame(0, Menu::query()->whereIn('id', $obsoleteMenuIds)->count());
            $this->assertSame(0, DB::table('menu_permission')->whereIn('menu_id', $obsoleteMenuIds)->count());
            $this->assertSame(0, DB::table('role_menu')->whereIn('menu_id', $obsoleteMenuIds)->count());
        } finally {
            Schema::dropIfExists('role_menu');
        }
    }

    public function test_system_settings_menu_migration_is_replayable_irreversible_and_removes_historical_bindings(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $systemSettingsPage = Menu::query()->where('code', 'system.configs')->firstOrFail();
        $systemSettingsPage->update(['name' => '系统配置']);
        $role = Role::query()->where('name', 'system-admin')->where('guard_name', 'admin')->firstOrFail();

        Schema::create('role_menu', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $table->primary(['role_id', 'menu_id']);
        });

        try {
            $obsoleteMenuIds = collect(['create', 'delete'])->map(function (string $action) use ($role, $systemSettingsPage): int {
                $menu = Menu::query()->create([
                    'parent_id' => $systemSettingsPage->id,
                    'name' => $action === 'create' ? '新增' : '删除',
                    'code' => "system.configs.{$action}",
                    'type' => Menu::TYPE_BUTTON,
                    'sort' => $action === 'create' ? 10 : 30,
                    'is_visible' => false,
                    'is_active' => true,
                ]);
                $permission = Permission::findByName("system.config.{$action}", 'admin');
                $menu->permissions()->attach($permission);
                DB::table('role_menu')->insert(['role_id' => $role->id, 'menu_id' => $menu->id]);

                return $menu->id;
            });

            $migration = require database_path('migrations/2026_08_12_085238_rename_system_config_menu_to_system_settings.php');
            $migration->up();
            $migration->up();
            $migration->down();

            $this->assertSame('系统设置', $systemSettingsPage->refresh()->name);
            $this->assertSame(0, Menu::query()->whereIn('id', $obsoleteMenuIds)->count());
            $this->assertSame(0, DB::table('menu_permission')->whereIn('menu_id', $obsoleteMenuIds)->count());
            $this->assertSame(0, DB::table('role_menu')->whereIn('menu_id', $obsoleteMenuIds)->count());
        } finally {
            Schema::dropIfExists('role_menu');
        }
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

        $routePermissionNames = $this->managedAdminPermissionNames();
        $menus = Menu::query()
            ->whereHas('permissions')
            ->with('permissions')
            ->get();
        $menuPermissionNames = $menus
            ->flatMap(fn (Menu $menu) => $menu->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $routeOnlyPermissionNames = ['system.config.create', 'system.config.delete'];

        $this->assertSame($routeOnlyPermissionNames, $routePermissionNames->diff($menuPermissionNames)->values()->all());
        $this->assertSame(
            $routePermissionNames->diff($routeOnlyPermissionNames)->values()->all(),
            $menuPermissionNames,
        );

        $menus->each(function (Menu $menu): void {
            $this->assertTrue($menu->permissions->isNotEmpty());
            $menu->permissions->each(fn (Permission $permission) => $this->assertSame('admin', $permission->guard_name));

            if ($menu->permissions->every(fn (Permission $permission): bool => str_ends_with($permission->name, '.view'))) {
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
