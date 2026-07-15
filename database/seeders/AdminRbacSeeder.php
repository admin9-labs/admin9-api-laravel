<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LogicException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminRbacSeeder extends Seeder
{
    private const ADMIN_GUARD = 'admin';

    private const DEFAULT_BOOTSTRAP_NAME = 'Admin';

    private const DEFAULT_BOOTSTRAP_EMAIL = 'admin@example.com';

    private const DEFAULT_BOOTSTRAP_PASSWORD = 'password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionDefinitions = collect($this->builtInPermissions());
        $permissions = $permissionDefinitions
            ->mapWithKeys(fn (array $definition): array => [
                $definition['name'] => $this->upsertPermission($definition),
            ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::findOrCreate('super-admin', self::ADMIN_GUARD);
        $systemAdmin = Role::findOrCreate('system-admin', self::ADMIN_GUARD);
        $systemAdmin->syncPermissions($permissions->values());

        $this->seedSuperAdmin($superAdmin);
        $this->seedMenus($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function upsertPermission(array $definition): Permission
    {
        /** @var Permission $permission */
        $permission = Permission::query()->updateOrCreate(
            [
                'name' => $definition['name'],
                'guard_name' => self::ADMIN_GUARD,
            ],
            [
                'display_name' => $definition['display_name'],
                'group' => $definition['group'],
                'description' => $definition['description'],
                'sort' => $definition['sort'],
                'is_system' => true,
                'is_active' => true,
            ],
        );

        return $permission;
    }

    private function seedSuperAdmin(Role $superAdmin): void
    {
        $bootstrapAdmin = $this->bootstrapAdmin();

        if ($bootstrapAdmin === null) {
            return;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $bootstrapAdmin['email']],
            ['name' => $bootstrapAdmin['name'], 'password' => $bootstrapAdmin['password'], 'is_active' => true]
        );

        if ($admin->wasRecentlyCreated) {
            $admin->assignRole($superAdmin);
        }
    }

    /**
     * @return array{name: string, email: string, password: string}|null
     */
    private function bootstrapAdmin(): ?array
    {
        $name = $this->filledBootstrapConfig('name') ?? self::DEFAULT_BOOTSTRAP_NAME;
        $email = $this->filledBootstrapConfig('email');
        $password = $this->filledBootstrapConfig('password');

        if (! app()->environment(['local', 'testing'])) {
            if ($email === null || $password === null || $password === self::DEFAULT_BOOTSTRAP_PASSWORD) {
                Log::warning('Skipping non-local bootstrap admin creation because explicit ADMIN_BOOTSTRAP_EMAIL and ADMIN_BOOTSTRAP_PASSWORD are required.');

                return null;
            }

            return [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ];
        }

        return [
            'name' => $name,
            'email' => $email ?? self::DEFAULT_BOOTSTRAP_EMAIL,
            'password' => $password ?? self::DEFAULT_BOOTSTRAP_PASSWORD,
        ];
    }

    private function filledBootstrapConfig(string $key): ?string
    {
        $value = config(sprintf('admin.bootstrap.%s', $key));

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  Collection<string, Permission>  $permissions
     */
    private function seedMenus(Collection $permissions): void
    {
        $system = Menu::query()->updateOrCreate(
            ['code' => 'system'],
            [
                'parent_id' => null,
                'name' => '系统管理',
                'path' => '/system',
                'component' => 'Layout',
                'icon' => 'settings',
                'type' => Menu::TYPE_DIRECTORY,
                'permission_id' => null,
                'permission_name' => null,
                'sort' => 10,
                'is_visible' => true,
                'is_active' => true,
            ]
        );

        $menus = ['system' => $system];

        foreach ($this->menuDefinitions() as $definition) {
            $permissionName = $definition['permission_name'];
            $permission = $permissionName === null ? null : $permissions->get($permissionName);

            if ($permissionName !== null && ! $permission instanceof Permission) {
                throw new LogicException(sprintf(
                    'Missing built-in permission [%s] for menu [%s].',
                    $permissionName,
                    $definition['code'],
                ));
            }

            $parent = $menus[$definition['parent_code']];

            $menus[$definition['code']] = Menu::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'parent_id' => $parent->id,
                    'name' => $definition['name'],
                    'path' => $definition['path'],
                    'component' => $definition['component'],
                    'icon' => $definition['icon'],
                    'type' => $definition['type'],
                    'permission_id' => $permission?->id,
                    'permission_name' => $permissionName,
                    'sort' => $definition['sort'],
                    'is_visible' => $definition['is_visible'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<int, array{name: string, display_name: string, group: string, description: string, sort: int}>
     */
    private function builtInPermissions(): array
    {
        return [
            ['name' => 'system.user.view', 'display_name' => '用户查看', 'group' => 'system.user', 'description' => '查看后台用户', 'sort' => 110],
            ['name' => 'system.user.create', 'display_name' => '用户创建', 'group' => 'system.user', 'description' => '创建后台用户', 'sort' => 120],
            ['name' => 'system.user.update', 'display_name' => '用户更新', 'group' => 'system.user', 'description' => '更新后台用户', 'sort' => 130],
            ['name' => 'system.user.delete', 'display_name' => '用户删除', 'group' => 'system.user', 'description' => '删除后台用户', 'sort' => 140],
            ['name' => 'system.user.assign-role', 'display_name' => '用户分配角色', 'group' => 'system.user', 'description' => '为后台用户分配角色', 'sort' => 150],
            ['name' => 'system.role.view', 'display_name' => '角色查看', 'group' => 'system.role', 'description' => '查看后台角色', 'sort' => 210],
            ['name' => 'system.role.create', 'display_name' => '角色创建', 'group' => 'system.role', 'description' => '创建后台角色', 'sort' => 220],
            ['name' => 'system.role.update', 'display_name' => '角色更新', 'group' => 'system.role', 'description' => '更新后台角色及权限', 'sort' => 230],
            ['name' => 'system.role.delete', 'display_name' => '角色删除', 'group' => 'system.role', 'description' => '删除后台角色', 'sort' => 240],
            ['name' => 'system.permission.view', 'display_name' => '权限查看', 'group' => 'system.permission', 'description' => '查看后台权限', 'sort' => 310],
            ['name' => 'system.permission.create', 'display_name' => '权限创建', 'group' => 'system.permission', 'description' => '创建动态权限', 'sort' => 320],
            ['name' => 'system.permission.update', 'display_name' => '权限更新', 'group' => 'system.permission', 'description' => '更新权限', 'sort' => 330],
            ['name' => 'system.permission.delete', 'display_name' => '权限删除', 'group' => 'system.permission', 'description' => '删除动态权限', 'sort' => 340],
            ['name' => 'system.menu.view', 'display_name' => '菜单查看', 'group' => 'system.menu', 'description' => '查看后台菜单', 'sort' => 410],
            ['name' => 'system.menu.create', 'display_name' => '菜单创建', 'group' => 'system.menu', 'description' => '创建后台菜单', 'sort' => 420],
            ['name' => 'system.menu.update', 'display_name' => '菜单更新', 'group' => 'system.menu', 'description' => '更新后台菜单', 'sort' => 430],
            ['name' => 'system.menu.delete', 'display_name' => '菜单删除', 'group' => 'system.menu', 'description' => '删除后台菜单', 'sort' => 440],
            ['name' => 'system.dictionary.view', 'display_name' => '字典查看', 'group' => 'system.dictionary', 'description' => '查看字典类型与字典项', 'sort' => 510],
            ['name' => 'system.dictionary.create', 'display_name' => '字典创建', 'group' => 'system.dictionary', 'description' => '创建字典类型与字典项', 'sort' => 520],
            ['name' => 'system.dictionary.update', 'display_name' => '字典更新', 'group' => 'system.dictionary', 'description' => '更新字典类型与字典项', 'sort' => 530],
            ['name' => 'system.dictionary.delete', 'display_name' => '字典删除', 'group' => 'system.dictionary', 'description' => '删除字典类型与字典项', 'sort' => 540],
            ['name' => 'system.config.view', 'display_name' => '系统配置查看', 'group' => 'system.config', 'description' => '查看系统配置', 'sort' => 610],
            ['name' => 'system.config.create', 'display_name' => '系统配置创建', 'group' => 'system.config', 'description' => '创建系统配置', 'sort' => 620],
            ['name' => 'system.config.update', 'display_name' => '系统配置更新', 'group' => 'system.config', 'description' => '更新系统配置', 'sort' => 630],
            ['name' => 'system.config.delete', 'display_name' => '系统配置删除', 'group' => 'system.config', 'description' => '删除系统配置', 'sort' => 640],
        ];
    }

    /**
     * @return array<int, array{code: string, parent_code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_name: ?string, sort: int, is_visible: bool}>
     */
    private function menuDefinitions(): array
    {
        return [
            ...$this->pageWithButtons('system.roles', 'system', '角色管理', '/system/roles', 'system/roles/index', 'team', 'system.role', 20),
            ...$this->pageWithButtons('system.permissions', 'system', '权限管理', '/system/permissions', 'system/permissions/index', 'lock', 'system.permission', 25),
            ...$this->pageWithButtons('system.users', 'system', '用户管理', '/system/users', 'system/users/index', 'user', 'system.user', 30, ['assign-role' => '分配角色']),
            ...$this->pageWithButtons('system.menus', 'system', '菜单管理', '/system/menus', 'system/menus/index', 'menu', 'system.menu', 40),
            ...$this->pageWithButtons('system.dictionaries', 'system', '字典管理', '/system/dictionaries', 'system/dictionaries/index', 'book', 'system.dictionary', 50),
            ...$this->pageWithButtons('system.configs', 'system', '系统配置', '/system/configs', 'system/configs/index', 'settings', 'system.config', 60),
        ];
    }

    /**
     * @param  array<string, string>  $extraButtons
     * @return array<int, array{code: string, parent_code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_name: ?string, sort: int, is_visible: bool}>
     */
    private function pageWithButtons(
        string $code,
        string $parentCode,
        string $name,
        string $path,
        string $component,
        string $icon,
        string $permissionPrefix,
        int $sort,
        array $extraButtons = [],
    ): array {
        $buttons = [
            'create' => '新增',
            'update' => '编辑',
            'delete' => '删除',
            ...$extraButtons,
        ];

        return [
            [
                'code' => $code,
                'parent_code' => $parentCode,
                'name' => $name,
                'path' => $path,
                'component' => $component,
                'icon' => $icon,
                'type' => Menu::TYPE_PAGE,
                'permission_name' => "{$permissionPrefix}.view",
                'sort' => $sort,
                'is_visible' => true,
            ],
            ...collect($buttons)
                ->map(fn (string $buttonName, string $action): array => [
                    'code' => "{$code}.{$action}",
                    'parent_code' => $code,
                    'name' => $buttonName,
                    'path' => null,
                    'component' => null,
                    'icon' => null,
                    'type' => Menu::TYPE_BUTTON,
                    'permission_name' => "{$permissionPrefix}.{$action}",
                    'sort' => match ($action) {
                        'create' => 10,
                        'update' => 20,
                        'delete' => 30,
                        default => 40,
                    },
                    'is_visible' => false,
                ])
                ->values()
                ->all(),
        ];
    }
}
