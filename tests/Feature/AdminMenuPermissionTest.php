<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use App\Support\ApiRouting;
use Database\Seeders\AdminRbacSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminMenuPermissionTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_current_admin_menu_tree_returns_only_visible_active_and_authorized_menus(): void
    {
        $menuPermission = $this->createAdminPermission('system.menu.view');
        $rolePermission = $this->createAdminPermission('system.role.view');

        $root = Menu::factory()->create(['code' => 'system', 'name' => '系统管理', 'sort' => 1]);
        $this->createMenu(['parent_id' => $root->id, 'code' => 'system.menus', 'sort' => 1], [$menuPermission]);
        $this->createMenu(['parent_id' => $root->id, 'code' => 'system.roles', 'sort' => 2], [$rolePermission]);
        $this->createMenu(['parent_id' => $root->id, 'code' => 'system.hidden', 'sort' => 3, 'is_visible' => false], [$rolePermission]);
        $this->createMenu(['parent_id' => $root->id, 'code' => 'system.inactive', 'sort' => 4, 'is_active' => false], [$rolePermission]);

        $user = User::factory()->create(['email' => 'menus@example.com']);
        $user->givePermissionTo($rolePermission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Request-Id');

        $codes = $this->menuCodes(collect($response->json('data')));

        $this->assertContains('system', $codes);
        $this->assertContains('system.roles', $codes);
        $this->assertNotContains('system.menus', $codes);
        $this->assertNotContains('system.hidden', $codes);
        $this->assertNotContains('system.inactive', $codes);
    }

    public function test_menu_tree_filters_by_permission_relation(): void
    {
        $deniedPermission = $this->createAdminPermission('system.denied.view');
        $canonicalPermission = $this->createAdminPermission('system.canonical.view');

        $root = Menu::factory()->create(['code' => 'canonical.root']);
        $this->createMenu([
            'parent_id' => $root->id,
            'code' => 'canonical.allowed',
        ], [$canonicalPermission]);
        $this->createMenu([
            'parent_id' => $root->id,
            'code' => 'canonical.denied',
        ], [$deniedPermission]);

        $user = User::factory()->create(['email' => 'canonical-menu@example.com']);
        $user->givePermissionTo($canonicalPermission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $codes = $this->menuCodes(collect($response->json('data')));

        $this->assertContains('canonical.allowed', $codes);
        $this->assertNotContains('canonical.denied', $codes);
    }

    public function test_menu_tree_allows_any_bound_permission_and_empty_bindings(): void
    {
        $activityPermission = $this->createAdminPermission('system.activity-log.view');
        $loginPermission = $this->createAdminPermission('system.login-log.view');
        $root = Menu::factory()->directory()->create(['code' => 'system']);
        $this->createMenu([
            'parent_id' => $root->id,
            'code' => 'system.logs',
        ], [$activityPermission, $loginPermission]);
        $this->createMenu([
            'parent_id' => $root->id,
            'code' => 'system.public',
        ]);

        foreach ([
            'activity-only@example.com' => [$activityPermission],
            'login-only@example.com' => [$loginPermission],
            'both-logs@example.com' => [$activityPermission, $loginPermission],
            'neither-log@example.com' => [],
        ] as $email => $permissions) {
            $user = User::factory()->create(['email' => $email]);

            if ($permissions !== []) {
                $user->givePermissionTo($permissions);
            }

            $response = $this->getJson(ApiRouting::path('/admin/menus/tree'), [
                'Authorization' => 'Bearer '.$this->adminTokenFor($user),
            ])->assertOk();
            $codes = $this->menuCodes(collect($response->json('data')));

            $this->assertContains('system.public', $codes);

            if ($permissions === []) {
                $this->assertNotContains('system.logs', $codes);
            } else {
                $this->assertContains('system.logs', $codes);
            }
        }
    }

    public function test_menu_tree_response_includes_permission_collections(): void
    {
        $permission = $this->createAdminPermission('system.compat.view');
        $this->createMenu([
            'code' => 'compat.menu',
        ], [$permission]);

        $user = User::factory()->create(['email' => 'compat-menu@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'code' => 'compat.menu',
                'permission_ids' => [$permission->id],
                'permission_names' => [$permission->name],
            ])
            ->assertJsonPath('data.0.permissions.0.name', $permission->name);
    }

    public function test_menu_store_syncs_permission_ids_and_omission_creates_empty_binding(): void
    {
        $permission = $this->createAdminPermission('system.menu.synced');
        $directory = Menu::factory()->directory()->create(['code' => 'synced']);
        $token = $this->managerTokenFor(['system.menu.create']);

        $response = $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Synced Menu',
            'code' => 'synced.menu',
            'parent_id' => $directory->id,
            'path' => '/synced/menu',
            'component' => 'synced/menu/index',
            'type' => Menu::TYPE_PAGE,
            'permission_ids' => [$permission->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.permission_ids', [$permission->id])
            ->assertJsonPath('data.menu.permission_names', [$permission->name])
            ->assertJsonPath('data.menu.permissions.0.name', $permission->name);

        $menu = Menu::query()->find($response->json('data.menu.id'));

        $this->assertNotNull($menu);
        $this->assertSame([$permission->id], $menu->permissions()->pluck('permissions.id')->all());

        $unrestricted = $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Unrestricted Menu',
            'code' => 'unrestricted.menu',
            'parent_id' => $directory->id,
            'type' => Menu::TYPE_PAGE,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.permission_ids', [])
            ->assertJsonPath('data.menu.permission_names', [])
            ->assertJsonPath('data.menu.permissions', []);

        $this->assertFalse(Menu::query()->findOrFail($unrestricted->json('data.menu.id'))->permissions()->exists());
    }

    public function test_menu_update_omission_preserves_permissions_and_explicit_empty_array_unbinds(): void
    {
        $oldPermission = $this->createAdminPermission('system.menu.old');
        $newPermission = $this->createAdminPermission('system.menu.new');
        $directory = Menu::factory()->directory()->create(['code' => 'synced']);
        $menu = $this->createMenu([
            'parent_id' => $directory->id,
            'code' => 'synced.menu.update',
        ], [$oldPermission]);
        $token = $this->managerTokenFor(['system.menu.update']);

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'name' => 'Permissions Preserved',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.permission_ids', [$oldPermission->id]);

        $this->assertSame([$oldPermission->id], $menu->refresh()->permissions()->pluck('permissions.id')->all());

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'permission_ids' => [$newPermission->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.permission_ids', [$newPermission->id])
            ->assertJsonPath('data.menu.permission_names', [$newPermission->name]);

        $this->assertSame([$newPermission->id], $menu->refresh()->permissions()->pluck('permissions.id')->all());

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'permission_ids' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.permission_ids', [])
            ->assertJsonPath('data.menu.permission_names', []);

        $this->assertFalse($menu->refresh()->permissions()->exists());
    }

    public function test_menu_update_persists_every_public_menu_field(): void
    {
        $permission = $this->createAdminPermission('system.menu.public-fields');
        $menu = Menu::factory()->directory()->create(['code' => 'public-fields.before']);
        $token = $this->managerTokenFor(['system.menu.update']);

        $this->putJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'parent_id' => null,
            'name' => 'Public Fields Updated',
            'code' => 'public-fields.after',
            'path' => '/updated/path',
            'component' => 'updated/component',
            'icon' => 'icon-menu',
            'type' => Menu::TYPE_DIRECTORY,
            'permission_ids' => [$permission->id],
            'sort' => 91,
            'is_visible' => false,
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.name', 'Public Fields Updated')
            ->assertJsonPath('data.menu.code', 'public-fields.after')
            ->assertJsonPath('data.menu.path', '/updated/path')
            ->assertJsonPath('data.menu.component', 'updated/component')
            ->assertJsonPath('data.menu.icon', 'icon-menu')
            ->assertJsonPath('data.menu.type', Menu::TYPE_DIRECTORY)
            ->assertJsonPath('data.menu.permission_ids', [$permission->id])
            ->assertJsonPath('data.menu.sort', 91)
            ->assertJsonPath('data.menu.is_visible', false)
            ->assertJsonPath('data.menu.is_active', false);

        $menu->refresh();
        $this->assertNull($menu->parent_id);
        $this->assertSame('public-fields.after', $menu->code);
        $this->assertSame([$permission->id], $menu->permissions()->pluck('permissions.id')->all());

        $this->putJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'icon' => null,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.icon', null);

        $this->assertNull($menu->refresh()->icon);
    }

    public function test_unchanged_historical_structure_and_icon_do_not_block_unrelated_updates(): void
    {
        $legacyParent = Menu::factory()->create([
            'parent_id' => null,
            'code' => 'legacy.root-page',
            'type' => Menu::TYPE_PAGE,
        ]);
        $legacyMenu = Menu::factory()->directory()->create([
            'parent_id' => $legacyParent->id,
            'code' => 'legacy.nested-directory',
            'icon' => 'LegacyIcon',
        ]);
        $token = $this->managerTokenFor(['system.menu.update']);

        $this->putJson(ApiRouting::path('/admin/menus/').$legacyMenu->id, [
            'name' => 'Legacy Menu Renamed',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.name', 'Legacy Menu Renamed')
            ->assertJsonPath('data.menu.icon', 'LegacyIcon');

        $this->putJson(ApiRouting::path('/admin/menus/').$legacyMenu->id, [
            'icon' => null,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.icon', null);

        $legacyMenu->refresh();
        $this->assertSame($legacyParent->id, $legacyMenu->parent_id);
        $this->assertSame(Menu::TYPE_DIRECTORY, $legacyMenu->type);
    }

    public function test_legacy_permission_name_input_is_rejected_instead_of_becoming_unrestricted(): void
    {
        $permission = $this->createAdminPermission('system.menu.legacy-input');
        $menu = $this->createMenu([
            'code' => 'legacy-input.existing',
        ], [$permission]);
        $token = $this->managerTokenFor(['system.menu.create', 'system.menu.update']);

        $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Legacy Name Input',
            'code' => 'legacy-input.created',
            'type' => Menu::TYPE_PAGE,
            'permission_name' => $permission->name,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('menus', ['code' => 'legacy-input.created']);

        $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Legacy ID Input',
            'code' => 'legacy-id.created',
            'permission_id' => $permission->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'permission_name' => null,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame([$permission->id], $menu->refresh()->permissions()->pluck('permissions.id')->all());
    }

    public function test_menu_response_reflects_permission_rename_from_relation(): void
    {
        $permission = $this->createAdminPermission('system.menu.before-rename');
        $menu = $this->createMenu([
            'code' => 'renamed.permission.menu',
        ], [$permission]);
        $token = $this->managerTokenFor(['system.menu.view', 'system.permission.update']);

        $this->patchJson(ApiRouting::path('/admin/permissions/').$permission->id, [
            'name' => 'system.menu.after-rename',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson(ApiRouting::path('/admin/menus/').$menu->id, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.permission_ids', [$permission->id])
            ->assertJsonPath('data.menu.permission_names', ['system.menu.after-rename'])
            ->assertJsonPath('data.menu.permissions.0.name', 'system.menu.after-rename');
    }

    public function test_menu_store_and_update_reject_non_admin_guard_permissions(): void
    {
        $memberPermission = SpatiePermission::findOrCreate('member.menu.view', 'member');
        $menu = Menu::factory()->create(['code' => 'admin-guard-only.menu']);
        $token = $this->managerTokenFor(['system.menu.create', 'system.menu.update']);

        $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Member Guard Menu',
            'code' => 'member-guard.menu',
            'type' => Menu::TYPE_PAGE,
            'permission_ids' => [$memberPermission->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('menus', ['code' => 'member-guard.menu']);

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'permission_ids' => [$memberPermission->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse($menu->refresh()->permissions()->exists());
    }

    public function test_menu_tree_returns_only_directory_and_page_nodes_for_navigation(): void
    {
        $permission = $this->createAdminPermission('system.navigation.view');
        $root = Menu::factory()->directory()->create([
            'code' => 'navigation.root',
        ]);
        $this->createMenu([
            'parent_id' => $root->id,
            'code' => 'navigation.page',
            'type' => Menu::TYPE_PAGE,
        ], [$permission]);
        $this->createMenu([
            'parent_id' => $root->id,
            'code' => 'navigation.page.create',
            'type' => Menu::TYPE_BUTTON,
            'path' => null,
            'component' => null,
            'is_visible' => false,
        ], [$permission]);

        $user = User::factory()->create(['email' => 'navigation-types@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $menus = collect($this->flattenMenus($response->json('data')));

        $this->assertContains('navigation.root', $menus->pluck('code'));
        $this->assertContains('navigation.page', $menus->pluck('code'));
        $this->assertNotContains('navigation.page.create', $menus->pluck('code'));
        $this->assertSame(
            [],
            $menus->pluck('type')->reject(fn (string $type): bool => in_array($type, [Menu::TYPE_DIRECTORY, Menu::TYPE_PAGE], true))->values()->all()
        );
    }

    public function test_menu_catalog_returns_complete_ordered_catalog_without_pagination(): void
    {
        $permission = $this->createAdminPermission('system.menu.view');
        $token = $this->managerTokenFor(['system.menu.view']);

        foreach (range(1, 6) as $number) {
            Menu::factory()->create([
                'code' => "catalog.menu.{$number}",
                'sort' => $number,
            ]);
        }

        $response = $this->getJson(ApiRouting::path('/admin/menus?page_size=2'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $codes = collect($response->json('data'))
            ->pluck('code')
            ->filter(fn (string $code): bool => str_starts_with($code, 'catalog.menu.'))
            ->values()
            ->all();

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertSame([
            'catalog.menu.1',
            'catalog.menu.2',
            'catalog.menu.3',
            'catalog.menu.4',
            'catalog.menu.5',
            'catalog.menu.6',
        ], $codes);
    }

    public function test_menu_tree_returns_complete_bounded_tree_without_pagination(): void
    {
        $permission = $this->createAdminPermission('system.tree.view');
        $root = Menu::factory()->create(['code' => 'bounded.root', 'sort' => 1]);

        foreach (range(1, 6) as $number) {
            $this->createMenu([
                'parent_id' => $root->id,
                'code' => "bounded.child.{$number}",
                'sort' => $number,
            ], [$permission]);
        }

        $user = User::factory()->create(['email' => 'bounded-menu-tree@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree?page_size=2'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertSame([
            'bounded.root',
            'bounded.child.1',
            'bounded.child.2',
            'bounded.child.3',
            'bounded.child.4',
            'bounded.child.5',
            'bounded.child.6',
        ], $this->menuCodes(collect($response->json('data'))));
    }

    public function test_super_admin_menu_tree_uses_shared_permission_checker(): void
    {
        $permission = $this->createAdminPermission('system.super-menu.view');
        $this->createMenu([
            'code' => 'system.super-menu',
        ], [$permission]);

        $user = User::factory()->create(['email' => 'super-menu@example.com']);
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));
        $token = $this->adminTokenFor($user);

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertContains('system.super-menu', $this->menuCodes(collect($response->json('data'))));
    }

    public function test_menu_tree_reuses_eager_loaded_permissions_for_authorization(): void
    {
        $permission = $this->createAdminPermission('system.query-budget.view');
        $root = Menu::factory()->directory()->create([
            'code' => 'query-budget.root',
        ]);

        foreach (range(1, 5) as $number) {
            $this->createMenu([
                'parent_id' => $root->id,
                'code' => "query-budget.child.{$number}",
                'sort' => $number,
                'type' => Menu::TYPE_PAGE,
            ], [$permission]);
        }

        $user = User::factory()->create(['email' => 'query-budget-menu@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        DB::enableQueryLog();

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $permissionSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "permissions"'))
            ->count();

        $this->assertContains('query-budget.child.5', $this->menuCodes(collect($response->json('data'))));
        $this->assertLessThanOrEqual(2, $permissionSelects);
    }

    public function test_hidden_menu_is_not_an_authorization_boundary(): void
    {
        $permission = $this->createAdminPermission('system.menu.view');
        $menu = $this->createMenu([
            'code' => 'system.hidden-visible-by-api',
            'is_visible' => false,
        ], [$permission]);

        $user = User::factory()->create(['email' => 'hidden-menu@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $tree = $this->getJson(ApiRouting::path('/admin/menus/tree'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotContains($menu->code, $this->menuCodes(collect($tree->json('data'))));

        $this->getJson(ApiRouting::path('/admin/menus/').$menu->id, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.code', 'system.hidden-visible-by-api');
    }

    public function test_menu_update_rejects_direct_parent_cycles(): void
    {
        $permission = $this->createAdminPermission('system.menu.update');
        $menu = Menu::factory()->create(['code' => 'cycle.direct']);
        $user = User::factory()->create(['email' => 'menu-direct-cycle@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'parent_id' => $menu->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($menu->refresh()->parent_id);
    }

    public function test_menu_update_rejects_indirect_descendant_parent_cycles(): void
    {
        $permission = $this->createAdminPermission('system.menu.update');

        $root = Menu::factory()->create(['code' => 'cycle.root']);
        $child = Menu::factory()->create(['parent_id' => $root->id, 'code' => 'cycle.child']);
        $grandchild = Menu::factory()->create(['parent_id' => $child->id, 'code' => 'cycle.grandchild']);

        $user = User::factory()->create(['email' => 'menu-cycle@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->patchJson(ApiRouting::path('/admin/menus/').$root->id, [
            'parent_id' => $grandchild->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($root->refresh()->parent_id);
    }

    public function test_menu_update_allows_legal_parent_changes(): void
    {
        $permission = $this->createAdminPermission('system.menu.update');
        $originalParent = Menu::factory()->directory()->create(['code' => 'reparent.original']);
        $newParent = Menu::factory()->directory()->create(['code' => 'reparent.new']);
        $menu = Menu::factory()->create([
            'parent_id' => $originalParent->id,
            'code' => 'reparent.child',
        ]);
        $user = User::factory()->create(['email' => 'menu-reparent@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->patchJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'parent_id' => $newParent->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.parent_id', $newParent->id);

        $this->assertSame($newParent->id, $menu->refresh()->parent_id);
    }

    public function test_seeded_leaf_page_can_become_a_root_directory_without_seeder_reverting_it(): void
    {
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $menu = Menu::query()->where('seed_key', 'admin9.core.system.logs')->firstOrFail();
        $menuId = $menu->id;
        $token = $this->managerTokenFor(['system.menu.update']);

        $this->putJson(ApiRouting::path('/admin/menus/').$menu->id, [
            'parent_id' => null,
            'type' => Menu::TYPE_DIRECTORY,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.parent_id', null)
            ->assertJsonPath('data.menu.type', Menu::TYPE_DIRECTORY);

        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);
        Artisan::call('db:seed', ['--class' => AdminRbacSeeder::class]);

        $menu->refresh();
        $this->assertSame($menuId, $menu->id);
        $this->assertNull($menu->parent_id);
        $this->assertSame(Menu::TYPE_DIRECTORY, $menu->type);
    }

    public function test_menu_store_enforces_directory_page_button_hierarchy(): void
    {
        $token = $this->managerTokenFor(['system.menu.create']);
        $directory = Menu::factory()->directory()->create(['code' => 'hierarchy.directory']);
        $page = Menu::factory()->create([
            'parent_id' => $directory->id,
            'code' => 'hierarchy.page',
        ]);

        foreach ([
            ['code' => 'invalid.directory', 'type' => Menu::TYPE_DIRECTORY, 'parent_id' => $directory->id],
            ['code' => 'invalid.page', 'type' => Menu::TYPE_PAGE, 'parent_id' => null],
            ['code' => 'invalid.button', 'type' => Menu::TYPE_BUTTON, 'parent_id' => $directory->id],
        ] as $payload) {
            $this->postJson(ApiRouting::path('/admin/menus'), [
                'name' => $payload['code'],
                ...$payload,
            ], ['Authorization' => 'Bearer '.$token])
                ->assertStatus(422)
                ->assertJsonValidationErrors('parent_id');
        }

        $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Valid Button',
            'code' => 'hierarchy.page.action',
            'type' => Menu::TYPE_BUTTON,
            'parent_id' => $page->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.menu.parent_id', $page->id);
    }

    public function test_menu_type_update_rejects_existing_child_type_mismatches(): void
    {
        $token = $this->managerTokenFor(['system.menu.update']);
        $directory = Menu::factory()->directory()->create(['code' => 'type.directory']);
        Menu::factory()->create([
            'parent_id' => $directory->id,
            'code' => 'type.page',
        ]);

        $this->patchJson(ApiRouting::path('/admin/menus/').$directory->id, [
            'type' => Menu::TYPE_BUTTON,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id', 'type']);

        $this->assertSame(Menu::TYPE_DIRECTORY, $directory->refresh()->type);
    }

    public function test_menu_icon_format_accepts_normalized_names_and_rejects_unsafe_values(): void
    {
        $token = $this->managerTokenFor(['system.menu.create']);

        foreach (['menu', 'icon-menu', null] as $index => $icon) {
            $this->postJson(ApiRouting::path('/admin/menus'), [
                'name' => "Directory {$index}",
                'code' => "icon.valid.{$index}",
                'type' => Menu::TYPE_DIRECTORY,
                'icon' => $icon,
            ], ['Authorization' => 'Bearer '.$token])
                ->assertOk()
                ->assertJsonPath('data.menu.icon', $icon);
        }

        $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Unsafe Icon',
            'code' => 'icon.invalid',
            'type' => Menu::TYPE_DIRECTORY,
            'icon' => '<script>',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('icon');
    }

    public function test_seed_identity_is_internal_and_seeded_leaf_deletion_records_tombstone(): void
    {
        $token = $this->managerTokenFor(['system.menu.view', 'system.menu.create', 'system.menu.delete']);
        $seeded = Menu::factory()->directory()->create(['code' => 'seeded.leaf']);
        $seeded->setAttribute('seed_key', 'admin9.test.seeded.leaf');
        $seeded->save();
        $custom = Menu::factory()->directory()->create(['code' => 'custom.leaf']);

        $response = $this->getJson(ApiRouting::path('/admin/menus/').$seeded->id, ['Authorization' => 'Bearer '.$token])
            ->assertOk();
        $menuData = $response->json('data.menu');
        $this->assertIsArray($menuData);
        $this->assertArrayNotHasKey('seed_key', $menuData);

        $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'Injected Seed Identity',
            'code' => 'seeded.injected',
            'type' => Menu::TYPE_DIRECTORY,
            'seed_key' => 'admin9.test.injected',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertDatabaseMissing('menus', ['seed_key' => 'admin9.test.injected']);

        $this->deleteJson(ApiRouting::path('/admin/menus/').$seeded->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();
        $this->deleteJson(ApiRouting::path('/admin/menus/').$custom->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertDatabaseHas('menu_seed_tombstones', ['seed_key' => 'admin9.test.seeded.leaf']);
        $this->assertDatabaseCount('menu_seed_tombstones', 1);
    }

    public function test_menu_with_child_menus_cannot_be_deleted(): void
    {
        $permission = $this->createAdminPermission('system.menu.delete');

        $parent = Menu::factory()->create(['code' => 'delete-guard.parent']);
        $child = Menu::factory()->create(['parent_id' => $parent->id, 'code' => 'delete-guard.child']);
        $leaf = Menu::factory()->create(['code' => 'delete-guard.leaf']);

        $user = User::factory()->create(['email' => 'menu-delete-guard@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->deleteJson(ApiRouting::path('/admin/menus/').$parent->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertModelExists($parent);
        $this->assertModelExists($child);
        $this->assertSame($parent->id, $child->refresh()->parent_id);

        $this->deleteJson(ApiRouting::path('/admin/menus/').$leaf->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing($leaf);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $menus
     * @return array<int, string>
     */
    private function menuCodes(Collection $menus): array
    {
        return $menus
            ->flatMap(function (array $menu): array {
                return [
                    $menu['code'],
                    ...$this->menuCodes(collect(Arr::get($menu, 'children', []))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $menus
     * @return array<int, array<string, mixed>>
     */
    private function flattenMenus(array $menus): array
    {
        return collect($menus)
            ->flatMap(function (array $menu): array {
                return [
                    $menu,
                    ...$this->flattenMenus(Arr::get($menu, 'children', [])),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, SpatiePermission>  $permissions
     */
    private function createMenu(array $attributes, array $permissions = []): Menu
    {
        $menu = Menu::factory()->create($attributes);
        $menu->permissions()->sync(
            collect($permissions)->map(fn (SpatiePermission $permission): int => (int) $permission->getKey())->all()
        );

        return $menu;
    }
}
