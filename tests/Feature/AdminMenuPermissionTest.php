<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        Menu::factory()->create(['parent_id' => $root->id, 'code' => 'system.menus', 'permission_id' => $menuPermission->id, 'permission_name' => 'system.menu.view', 'sort' => 1]);
        Menu::factory()->create(['parent_id' => $root->id, 'code' => 'system.roles', 'permission_id' => $rolePermission->id, 'permission_name' => 'system.role.view', 'sort' => 2]);
        Menu::factory()->hidden()->create(['parent_id' => $root->id, 'code' => 'system.hidden', 'permission_id' => $rolePermission->id, 'permission_name' => 'system.role.view', 'sort' => 3]);
        Menu::factory()->inactive()->create(['parent_id' => $root->id, 'code' => 'system.inactive', 'permission_id' => $rolePermission->id, 'permission_name' => 'system.role.view', 'sort' => 4]);

        $user = User::factory()->create(['email' => 'menus@example.com']);
        $user->givePermissionTo($rolePermission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
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

    public function test_menu_tree_filters_by_canonical_permission_relation(): void
    {
        $legacyPermission = $this->createAdminPermission('system.legacy.view');
        $canonicalPermission = $this->createAdminPermission('system.canonical.view');

        $root = Menu::factory()->create(['code' => 'canonical.root', 'permission_id' => null, 'permission_name' => null]);
        Menu::factory()->create([
            'parent_id' => $root->id,
            'code' => 'canonical.allowed',
            'permission_id' => $canonicalPermission->id,
            'permission_name' => $legacyPermission->name,
        ]);
        Menu::factory()->create([
            'parent_id' => $root->id,
            'code' => 'canonical.denied',
            'permission_id' => $legacyPermission->id,
            'permission_name' => $canonicalPermission->name,
        ]);

        $user = User::factory()->create(['email' => 'canonical-menu@example.com']);
        $user->givePermissionTo($canonicalPermission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $codes = $this->menuCodes(collect($response->json('data')));

        $this->assertContains('canonical.allowed', $codes);
        $this->assertNotContains('canonical.denied', $codes);
    }

    public function test_menu_tree_response_includes_permission_name(): void
    {
        $permission = $this->createAdminPermission('system.compat.view');
        Menu::factory()->create([
            'code' => 'compat.menu',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ]);

        $user = User::factory()->create(['email' => 'compat-menu@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'code' => 'compat.menu',
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
            ]);
    }

    public function test_menu_tree_returns_only_directory_and_page_nodes_for_navigation(): void
    {
        $permission = $this->createAdminPermission('system.navigation.view');
        $root = Menu::factory()->directory()->create([
            'code' => 'navigation.root',
            'permission_id' => null,
            'permission_name' => null,
        ]);
        Menu::factory()->page()->create([
            'parent_id' => $root->id,
            'code' => 'navigation.page',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ]);
        Menu::factory()->button()->create([
            'parent_id' => $root->id,
            'code' => 'navigation.page.create',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ]);

        $user = User::factory()->create(['email' => 'navigation-types@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
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

        $response = $this->getJson('/api/admin/menus?page_size=2', ['Authorization' => 'Bearer '.$token])
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
            Menu::factory()->create([
                'parent_id' => $root->id,
                'code' => "bounded.child.{$number}",
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'sort' => $number,
            ]);
        }

        $user = User::factory()->create(['email' => 'bounded-menu-tree@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson('/api/admin/menus/tree?page_size=2', ['Authorization' => 'Bearer '.$token])
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
        Menu::factory()->create([
            'code' => 'system.super-menu',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ]);

        $user = User::factory()->create(['email' => 'super-menu@example.com']);
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));
        $token = $this->adminTokenFor($user);

        $response = $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertContains('system.super-menu', $this->menuCodes(collect($response->json('data'))));
    }

    public function test_menu_tree_reuses_eager_loaded_permissions_for_authorization(): void
    {
        $permission = $this->createAdminPermission('system.query-budget.view');
        $root = Menu::factory()->directory()->create([
            'code' => 'query-budget.root',
            'permission_id' => null,
            'permission_name' => null,
        ]);

        foreach (range(1, 5) as $number) {
            Menu::factory()->page()->create([
                'parent_id' => $root->id,
                'code' => "query-budget.child.{$number}",
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'sort' => $number,
            ]);
        }

        $user = User::factory()->create(['email' => 'query-budget-menu@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        DB::enableQueryLog();

        $response = $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
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
        $menu = Menu::factory()->hidden()->create([
            'code' => 'system.hidden-visible-by-api',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ]);

        $user = User::factory()->create(['email' => 'hidden-menu@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $tree = $this->getJson('/api/admin/menus/tree', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotContains($menu->code, $this->menuCodes(collect($tree->json('data'))));

        $this->getJson('/api/admin/menus/'.$menu->id, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.code', 'system.hidden-visible-by-api');
    }

    public function test_menu_update_rejects_descendant_parent_cycles(): void
    {
        $permission = $this->createAdminPermission('system.menu.update');

        $root = Menu::factory()->create(['code' => 'cycle.root']);
        $child = Menu::factory()->create(['parent_id' => $root->id, 'code' => 'cycle.child']);
        $grandchild = Menu::factory()->create(['parent_id' => $child->id, 'code' => 'cycle.grandchild']);

        $user = User::factory()->create(['email' => 'menu-cycle@example.com']);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $this->patchJson('/api/admin/menus/'.$root->id, [
            'parent_id' => $grandchild->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertNull($root->refresh()->parent_id);
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

        $this->deleteJson('/api/admin/menus/'.$parent->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertModelExists($parent);
        $this->assertModelExists($child);
        $this->assertSame($parent->id, $child->refresh()->parent_id);

        $this->deleteJson('/api/admin/menus/'.$leaf->id, [], ['Authorization' => 'Bearer '.$token])
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
}
