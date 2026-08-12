<?php

namespace Tests\Feature;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use App\Models\Menu;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPaginationMetadataTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  callable(): int  $seedRecords
     * @param  array<int, string>  $expectedDataKeys
     */
    #[DataProvider('paginatedAdminIndexEndpoints')]
    public function test_paginated_admin_resource_indexes_include_pagination_metadata(string $permission, string $path, callable $seedRecords, array $expectedDataKeys): void
    {
        $this->createPermission($permission);

        $user = User::factory()->create(['email' => fake()->unique()->safeEmail()]);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $expectedTotal = $seedRecords();

        $response = $this->getJson(ApiRouting::path($path), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination', 'page')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 2)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.total', $expectedTotal)
            ->assertJsonCount(2, 'data')
            ->assertHeader('X-Request-Id');

        foreach ($expectedDataKeys as $key) {
            $response->assertJsonPath("data.0.$key", fn (mixed $value): bool => $value !== null);
        }
    }

    /**
     * @param  callable(): array<int, string>  $seedRecords
     */
    #[DataProvider('boundedAdminCatalogEndpoints')]
    public function test_bounded_admin_catalog_indexes_return_complete_collections_without_pagination_metadata(
        string $permission,
        string $path,
        callable $seedRecords,
        string $identifierKey
    ): void {
        $this->createPermission($permission);

        $user = User::factory()->create(['email' => fake()->unique()->safeEmail()]);
        $user->givePermissionTo($permission);
        $token = $this->adminTokenFor($user);

        $expectedIdentifiers = $seedRecords();

        $response = $this->getJson(ApiRouting::path($path), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Request-Id');

        $payload = $response->json();
        $this->assertArrayNotHasKey('meta', $payload);
        $this->assertArrayNotHasKey('links', $payload);

        $actualIdentifiers = collect($response->json('data'))
            ->pluck($identifierKey)
            ->filter(fn (string $identifier): bool => in_array($identifier, $expectedIdentifiers, true))
            ->values()
            ->all();

        $this->assertSame($expectedIdentifiers, $actualIdentifiers);
    }

    public function test_bounded_menu_tree_returns_complete_authorized_tree_without_pagination_metadata(): void
    {
        $root = Menu::factory()->create([
            'code' => 'bounded.tree.root',
            'sort' => 1,
        ]);

        foreach (range(1, 4) as $number) {
            Menu::factory()->create([
                'parent_id' => $root->id,
                'code' => "bounded.tree.child.{$number}",
                'sort' => $number,
            ]);
        }

        $user = User::factory()->create(['email' => fake()->unique()->safeEmail()]);
        $token = $this->adminTokenFor($user);

        $response = $this->getJson(ApiRouting::path('/admin/menus/tree?page_size=2'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Request-Id');

        $payload = $response->json();
        $this->assertArrayNotHasKey('meta', $payload);
        $this->assertArrayNotHasKey('links', $payload);
        $this->assertSame([
            'bounded.tree.root',
            'bounded.tree.child.1',
            'bounded.tree.child.2',
            'bounded.tree.child.3',
            'bounded.tree.child.4',
        ], $this->menuCodes($response->json('data')));
    }

    /**
     * @return array<string, array{permission: string, path: string, seedRecords: callable(): int, expectedDataKeys: array<int, string>}>
     */
    public static function paginatedAdminIndexEndpoints(): array
    {
        return [
            'users' => [
                'permission' => 'system.user.view',
                'path' => '/admin/users?page_size=2',
                'seedRecords' => static function (): int {
                    User::factory()->count(2)->create();

                    return User::query()->count();
                },
                'expectedDataKeys' => ['id', 'email'],
            ],
            'dictionary types' => [
                'permission' => 'system.dictionary.view',
                'path' => '/admin/dictionary-types?page_size=2',
                'seedRecords' => static function (): int {
                    DictionaryType::factory()->count(3)->create();

                    return DictionaryType::query()->count();
                },
                'expectedDataKeys' => ['id', 'code'],
            ],
            'dictionary items' => [
                'permission' => 'system.dictionary.view',
                'path' => '/admin/dictionary-items?page_size=2',
                'seedRecords' => static function (): int {
                    $dictionaryType = DictionaryType::factory()->create();
                    DictionaryItem::factory()->count(3)->create([
                        'dictionary_type_id' => $dictionaryType->id,
                    ]);

                    return DictionaryItem::query()->count();
                },
                'expectedDataKeys' => ['id', 'code', 'type.id'],
            ],
            'system configs' => [
                'permission' => 'system.config.view',
                'path' => '/admin/system-configs?page_size=2',
                'seedRecords' => static function (): int {
                    SystemConfig::factory()->count(3)->create();

                    return SystemConfig::query()->count();
                },
                'expectedDataKeys' => ['id', 'key'],
            ],
        ];
    }

    /**
     * @return array<string, array{permission: string, path: string, seedRecords: callable(): array<int, string>, identifierKey: string}>
     */
    public static function boundedAdminCatalogEndpoints(): array
    {
        return [
            'roles' => [
                'permission' => 'system.role.view',
                'path' => '/admin/roles?page_size=2',
                'seedRecords' => static function (): array {
                    foreach (range(1, 4) as $number) {
                        Role::findOrCreate("bounded-catalog-role-{$number}", 'admin');
                    }

                    return [
                        'bounded-catalog-role-1',
                        'bounded-catalog-role-2',
                        'bounded-catalog-role-3',
                        'bounded-catalog-role-4',
                    ];
                },
                'identifierKey' => 'name',
            ],
            'permissions' => [
                'permission' => 'system.permission.view',
                'path' => '/admin/permissions?page_size=2',
                'seedRecords' => static function (): array {
                    foreach (range(1, 4) as $number) {
                        Permission::findOrCreate("bounded.catalog.permission.{$number}", 'admin');
                    }

                    return [
                        'bounded.catalog.permission.1',
                        'bounded.catalog.permission.2',
                        'bounded.catalog.permission.3',
                        'bounded.catalog.permission.4',
                    ];
                },
                'identifierKey' => 'name',
            ],
            'menus' => [
                'permission' => 'system.menu.view',
                'path' => '/admin/menus?page_size=2',
                'seedRecords' => static function (): array {
                    foreach (range(1, 4) as $number) {
                        Menu::factory()->create([
                            'code' => "bounded.catalog.menu.{$number}",
                            'sort' => $number,
                        ]);
                    }

                    return [
                        'bounded.catalog.menu.1',
                        'bounded.catalog.menu.2',
                        'bounded.catalog.menu.3',
                        'bounded.catalog.menu.4',
                    ];
                },
                'identifierKey' => 'code',
            ],
        ];
    }

    private function createPermission(string $permissionName): Permission
    {
        $permission = Permission::findOrCreate($permissionName, 'admin');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permission;
    }

    private function adminTokenFor(User $user): string
    {
        $response = $this->postJson(ApiRouting::path('/admin/auth/login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<int, array<string, mixed>>  $menus
     * @return array<int, string>
     */
    private function menuCodes(array $menus): array
    {
        $codes = [];

        foreach ($menus as $menu) {
            $codes[] = (string) $menu['code'];
            $codes = [
                ...$codes,
                ...$this->menuCodes($menu['children'] ?? []),
            ];
        }

        return $codes;
    }
}
