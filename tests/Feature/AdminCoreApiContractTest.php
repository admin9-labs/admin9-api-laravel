<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminCoreApiContractTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    /**
     * @param  callable(self): void  $seedResource
     * @param  array<int, string>  $itemKeys
     */
    #[DataProvider('coreCatalogEndpoints')]
    public function test_core_catalog_indexes_keep_stable_resource_shapes(
        string $permissionName,
        string $path,
        callable $seedResource,
        array $itemKeys
    ): void {
        $seedResource($this);
        $token = $this->managerTokenFor([$permissionName]);

        $response = $this->getJson($path, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Request-Id');

        $payload = $response->json();
        $this->assertSame($this->collectionTopLevelKeys(), array_keys($payload));
        $this->assertArrayNotHasKey('meta', $payload);
        $this->assertArrayNotHasKey('links', $payload);

        $item = $response->json('data.0');
        $this->assertIsArray($item);
        $this->assertSame($itemKeys, array_keys($item));
        $this->assertNoLegacyPermissionRouteFields($item);
    }

    public function test_user_index_keeps_paginated_resource_shape(): void
    {
        $token = $this->managerTokenFor(['system.user.view']);
        User::factory()->count(3)->create();

        $response = $this->getJson('/api/admin/users?page_size=2', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination', 'page')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 2)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.total', 4)
            ->assertHeader('X-Request-Id');

        $payload = $response->json();
        $this->assertSame(['success', 'code', 'message', 'data', 'meta', 'request_id'], array_keys($payload));

        $user = $response->json('data.0');
        $this->assertIsArray($user);
        $this->assertSame($this->userResourceKeys(), array_keys($user));
        $this->assertArrayNotHasKey('password', $user);
        $this->assertNoLegacyPermissionRouteFields($user);
    }

    public function test_menu_write_responses_keep_stable_nested_menu_shape(): void
    {
        $permission = $this->createAdminPermission('system.contract.menu');
        $token = $this->managerTokenFor([
            'system.menu.create',
            'system.menu.view',
            'system.menu.update',
        ]);

        $create = $this->postJson('/api/admin/menus', [
            'name' => 'Contract Menu',
            'code' => 'contract.menu',
            'path' => '/contract/menu',
            'component' => 'contract/menu/index',
            'type' => Menu::TYPE_PAGE,
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.type', Menu::TYPE_PAGE)
            ->assertJsonPath('data.menu.permission_id', $permission->id)
            ->assertJsonPath('data.menu.permission_name', $permission->name);

        $this->assertWrappedResourceShape($create, 'menu', $this->menuResourceKeys());

        $menuId = $create->json('data.menu.id');
        $this->assertIsInt($menuId);

        $show = $this->getJson('/api/admin/menus/'.$menuId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'menu', $this->menuResourceKeys());

        $update = $this->patchJson('/api/admin/menus/'.$menuId, [
            'type' => Menu::TYPE_BUTTON,
            'path' => null,
            'component' => null,
            'is_visible' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.menu.type', Menu::TYPE_BUTTON)
            ->assertJsonPath('data.menu.is_visible', false);
        $this->assertWrappedResourceShape($update, 'menu', $this->menuResourceKeys());
    }

    public function test_permission_write_responses_keep_stable_nested_permission_shape(): void
    {
        $token = $this->managerTokenFor([
            'system.permission.create',
            'system.permission.view',
            'system.permission.update',
        ]);

        $create = $this->postJson('/api/admin/permissions', [
            'name' => 'dynamic.contract.view',
            'display_name' => 'Contract View',
            'group' => 'dynamic.contract',
            'description' => 'View contract resources',
            'sort' => 70,
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.permission.guard_name', 'admin')
            ->assertJsonPath('data.permission.is_system', false);

        $this->assertWrappedResourceShape($create, 'permission', $this->permissionResourceKeys());

        $permissionId = $create->json('data.permission.id');
        $this->assertIsInt($permissionId);

        $show = $this->getJson('/api/admin/permissions/'.$permissionId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'permission', $this->permissionResourceKeys());

        $update = $this->patchJson('/api/admin/permissions/'.$permissionId, [
            'display_name' => 'Contract Read',
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.permission.display_name', 'Contract Read')
            ->assertJsonPath('data.permission.is_active', false);
        $this->assertWrappedResourceShape($update, 'permission', $this->permissionResourceKeys());
    }

    public function test_role_write_and_sync_responses_keep_stable_nested_role_shape(): void
    {
        $permission = $this->createAdminPermission('dynamic.contract.role');
        $token = $this->managerTokenFor([
            'system.role.create',
            'system.role.view',
            'system.role.update',
        ]);

        $create = $this->postJson('/api/admin/roles', [
            'name' => 'contract-role',
            'permissions' => [$permission->name],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role.guard_name', 'admin')
            ->assertJsonPath('data.role.permissions.0.name', $permission->name);

        $this->assertWrappedResourceShape($create, 'role', $this->roleResourceKeys());
        $this->assertPermissionCollectionShape($create->json('data.role.permissions'));

        $roleId = $create->json('data.role.id');
        $this->assertIsInt($roleId);

        $show = $this->getJson('/api/admin/roles/'.$roleId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'role', $this->roleResourceKeys());

        $sync = $this->putJson('/api/admin/roles/'.$roleId.'/permissions', [
            'permissions' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role.permissions', []);
        $this->assertWrappedResourceShape($sync, 'role', $this->roleResourceKeys());
    }

    public function test_user_write_and_role_sync_responses_keep_stable_nested_user_shape(): void
    {
        Role::findOrCreate('contract-user-role', 'admin');
        $token = $this->managerTokenFor([
            'system.user.create',
            'system.user.view',
            'system.user.update',
            'system.user.assign-role',
        ]);

        $create = $this->postJson('/api/admin/users', [
            'name' => 'Contract User',
            'email' => 'contract-user@example.com',
            'password' => 'password',
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'contract-user@example.com');

        $this->assertWrappedResourceShape($create, 'user', $this->userResourceKeys());
        $this->assertArrayNotHasKey('password', $create->json('data.user'));

        $userId = $create->json('data.user.id');
        $this->assertIsInt($userId);

        $show = $this->getJson('/api/admin/users/'.$userId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'user', $this->userResourceKeys());

        $update = $this->patchJson('/api/admin/users/'.$userId, [
            'name' => 'Contract User Updated',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Contract User Updated');
        $this->assertWrappedResourceShape($update, 'user', $this->userResourceKeys());

        $sync = $this->putJson('/api/admin/users/'.$userId.'/roles', [
            'roles' => ['contract-user-role'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.roles.0.name', 'contract-user-role');
        $this->assertWrappedResourceShape($sync, 'user', $this->userResourceKeys());
    }

    public function test_dictionary_type_write_responses_keep_stable_nested_shape(): void
    {
        $token = $this->managerTokenFor([
            'system.dictionary.create',
            'system.dictionary.view',
            'system.dictionary.update',
        ]);

        $create = $this->postJson('/api/admin/dictionary-types', [
            'name' => 'Contract Status',
            'code' => 'contract_status',
            'description' => 'Contract status values',
            'sort' => 10,
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_type.items_count', 0);

        $this->assertWrappedResourceShape($create, 'dictionary_type', $this->dictionaryTypeResourceKeys());

        $typeId = $create->json('data.dictionary_type.id');
        $this->assertIsInt($typeId);

        $show = $this->getJson('/api/admin/dictionary-types/'.$typeId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'dictionary_type', $this->dictionaryTypeResourceKeys());

        $update = $this->patchJson('/api/admin/dictionary-types/'.$typeId, [
            'name' => 'Contract Status Updated',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_type.name', 'Contract Status Updated');
        $this->assertWrappedResourceShape($update, 'dictionary_type', $this->dictionaryTypeResourceKeys());
    }

    public function test_dictionary_item_write_responses_keep_stable_nested_shape(): void
    {
        $token = $this->managerTokenFor([
            'system.dictionary.create',
            'system.dictionary.view',
            'system.dictionary.update',
        ]);
        $typeId = $this->postJson('/api/admin/dictionary-types', [
            'name' => 'Item Contract Type',
            'code' => 'item_contract_type',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->json('data.dictionary_type.id');
        $this->assertIsInt($typeId);

        $create = $this->postJson('/api/admin/dictionary-items', [
            'dictionary_type_id' => $typeId,
            'name' => 'Enabled',
            'code' => 'enabled',
            'value' => '1',
            'meta' => ['color' => 'green'],
            'sort' => 10,
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_item.type.id', $typeId);

        $this->assertWrappedResourceShape($create, 'dictionary_item', $this->dictionaryItemResourceKeys());
        $this->assertSame($this->nestedDictionaryTypeResourceKeys(), array_keys($create->json('data.dictionary_item.type')));

        $itemId = $create->json('data.dictionary_item.id');
        $this->assertIsInt($itemId);

        $show = $this->getJson('/api/admin/dictionary-items/'.$itemId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'dictionary_item', $this->dictionaryItemResourceKeys());

        $update = $this->patchJson('/api/admin/dictionary-items/'.$itemId, [
            'name' => 'Enabled Updated',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_item.name', 'Enabled Updated');
        $this->assertWrappedResourceShape($update, 'dictionary_item', $this->dictionaryItemResourceKeys());
    }

    public function test_system_config_write_responses_keep_stable_nested_shape(): void
    {
        $token = $this->managerTokenFor([
            'system.config.create',
            'system.config.view',
            'system.config.update',
        ]);

        $create = $this->postJson('/api/admin/system-configs', [
            'name' => 'Contract Config',
            'key' => 'contract.config',
            'value' => '{"enabled":true}',
            'type' => SystemConfig::TYPE_JSON,
            'config_group' => 'contract',
            'description' => 'Contract config payload',
            'is_public' => true,
            'is_active' => true,
            'sort' => 10,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.value.enabled', true);

        $this->assertWrappedResourceShape($create, 'system_config', $this->systemConfigResourceKeys());

        $configId = $create->json('data.system_config.id');
        $this->assertIsInt($configId);

        $show = $this->getJson('/api/admin/system-configs/'.$configId, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertWrappedResourceShape($show, 'system_config', $this->systemConfigResourceKeys());

        $update = $this->patchJson('/api/admin/system-configs/'.$configId, [
            'name' => 'Contract Config Updated',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.name', 'Contract Config Updated');
        $this->assertWrappedResourceShape($update, 'system_config', $this->systemConfigResourceKeys());
    }

    /**
     * @return array<string, array{permissionName: string, path: string, seedResource: callable(self): void, itemKeys: array<int, string>}>
     */
    public static function coreCatalogEndpoints(): array
    {
        return [
            'menus catalog' => [
                'permissionName' => 'system.menu.view',
                'path' => '/api/admin/menus?page_size=1',
                'seedResource' => static function (self $test): void {
                    $permission = $test->createAdminPermission('system.contract.menu.view');

                    Menu::factory()->create([
                        'code' => 'contract.menu.catalog',
                        'permission_id' => $permission->id,
                        'permission_name' => $permission->name,
                    ]);
                },
                'itemKeys' => self::menuResourceKeys(),
            ],
            'permissions catalog' => [
                'permissionName' => 'system.permission.view',
                'path' => '/api/admin/permissions?page_size=1',
                'seedResource' => static function (self $test): void {
                    $test->createAdminPermission('dynamic.contract.catalog', [
                        'display_name' => 'Contract Catalog',
                        'group' => 'dynamic.contract',
                    ]);
                },
                'itemKeys' => self::permissionResourceKeys(),
            ],
            'roles catalog' => [
                'permissionName' => 'system.role.view',
                'path' => '/api/admin/roles?page_size=1',
                'seedResource' => static function (): void {
                    Role::findOrCreate('contract-catalog-role', 'admin');
                },
                'itemKeys' => self::roleResourceKeys(),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function menuResourceKeys(): array
    {
        return [
            'id',
            'parent_id',
            'name',
            'code',
            'path',
            'component',
            'icon',
            'type',
            'permission_id',
            'permission_name',
            'permission',
            'sort',
            'is_visible',
            'is_active',
            'children',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function permissionResourceKeys(): array
    {
        return [
            'id',
            'name',
            'guard_name',
            'display_name',
            'group',
            'description',
            'sort',
            'is_system',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function roleResourceKeys(): array
    {
        return [
            'id',
            'name',
            'guard_name',
            'permissions',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function userResourceKeys(): array
    {
        return [
            'id',
            'name',
            'email',
            'is_active',
            'last_login_at',
            'last_login_ip',
            'roles',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function dictionaryTypeResourceKeys(): array
    {
        return [
            'id',
            'name',
            'code',
            'description',
            'sort',
            'is_active',
            'items_count',
            'items',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function nestedDictionaryTypeResourceKeys(): array
    {
        return [
            'id',
            'name',
            'code',
            'description',
            'sort',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function dictionaryItemResourceKeys(): array
    {
        return [
            'id',
            'dictionary_type_id',
            'name',
            'code',
            'value',
            'description',
            'meta',
            'sort',
            'is_active',
            'type',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function systemConfigResourceKeys(): array
    {
        return [
            'id',
            'name',
            'key',
            'value',
            'type',
            'config_group',
            'description',
            'is_public',
            'is_active',
            'sort',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $permissions
     */
    private function assertPermissionCollectionShape(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->assertSame(self::permissionResourceKeys(), array_keys($permission));
            $this->assertNoLegacyPermissionRouteFields($permission);
        }
    }

    /**
     * @param  array<int|string, mixed>  $item
     */
    private function assertNoLegacyPermissionRouteFields(array $item): void
    {
        $this->assertArrayNotHasKey('routes', $item);
        $this->assertArrayNotHasKey('route_names', $item);
        $this->assertArrayNotHasKey('admin_permission_routes', $item);
    }

    /**
     * @param  array<int, string>  $resourceKeys
     */
    private function assertWrappedResourceShape(TestResponse $response, string $resourceKey, array $resourceKeys): void
    {
        $payload = $response->json();

        $this->assertSame($this->collectionTopLevelKeys(), array_keys($payload));
        $this->assertSame([$resourceKey], array_keys($payload['data']));
        $this->assertSame($resourceKeys, array_keys($payload['data'][$resourceKey]));
        $this->assertNoLegacyPermissionRouteFields($payload['data'][$resourceKey]);
    }

    /**
     * @return array<int, string>
     */
    private function collectionTopLevelKeys(): array
    {
        return ['success', 'code', 'message', 'data', 'request_id'];
    }
}
