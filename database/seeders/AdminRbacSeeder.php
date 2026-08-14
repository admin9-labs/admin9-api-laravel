<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\SeededMenuProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminRbacSeeder extends Seeder
{
    private const ADMIN_GUARD = 'admin';

    private const DEFAULT_BOOTSTRAP_NAME = 'Admin';

    private const DEFAULT_BOOTSTRAP_EMAIL = 'admin@admin9.dev';

    private const DEFAULT_BOOTSTRAP_PASSWORD = 'password';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        try {
            DB::transaction(function () use ($permissionRegistrar): void {
                $permissionDefinitions = collect($this->builtInPermissions());
                $permissions = $permissionDefinitions
                    ->mapWithKeys(fn (array $definition): array => [
                        $definition['name'] => $this->upsertPermission($definition),
                    ]);

                $permissionRegistrar->forgetCachedPermissions();

                $superAdmin = Role::findOrCreate('super-admin', self::ADMIN_GUARD);
                $systemAdmin = Role::findOrCreate('system-admin', self::ADMIN_GUARD);
                $systemAdmin->syncPermissions($permissions->values());

                if (app()->environment(['local', 'testing'])) {
                    $this->seedSuperAdmin($superAdmin);
                }

                $this->seedMenus();
                $this->call(AdminAuditLogMenuSeeder::class);
            }, 5);
        } finally {
            $permissionRegistrar->forgetCachedPermissions();
        }
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
        $admin = User::query()->firstOrCreate(
            ['email' => self::DEFAULT_BOOTSTRAP_EMAIL],
            ['name' => self::DEFAULT_BOOTSTRAP_NAME, 'password' => self::DEFAULT_BOOTSTRAP_PASSWORD, 'is_active' => true]
        );

        if ($admin->wasRecentlyCreated) {
            $admin->assignRole($superAdmin);
        }
    }

    private function seedMenus(): void
    {
        $definitions = [
            [
                'seed_key' => 'admin9.core.system',
                'parent_seed_key' => null,
                'code' => 'system',
                'name' => '系统管理',
                'path' => '/system',
                'component' => 'Layout',
                'icon' => 'settings',
                'type' => Menu::TYPE_DIRECTORY,
                'permission_names' => [],
                'sort' => 10,
                'is_visible' => true,
                'is_active' => true,
            ],
            ...$this->menuDefinitions(),
        ];

        $warnings = app(SeededMenuProvisioner::class)->provision($definitions);

        foreach ($warnings as $warning) {
            $this->command?->warn($warning);
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
            ['name' => 'system.member.view', 'display_name' => '会员查看', 'group' => 'system.member', 'description' => '查看会员', 'sort' => 810],
            ['name' => 'system.member.create', 'display_name' => '会员创建', 'group' => 'system.member', 'description' => '创建会员', 'sort' => 820],
            ['name' => 'system.member.update', 'display_name' => '会员更新', 'group' => 'system.member', 'description' => '更新会员资料', 'sort' => 830],
            ['name' => 'system.member.status', 'display_name' => '会员启停', 'group' => 'system.member', 'description' => '启用或停用会员', 'sort' => 840],
            ['name' => 'system.member.reset_password', 'display_name' => '会员重置密码', 'group' => 'system.member', 'description' => '重置会员密码', 'sort' => 850],
            ['name' => 'system.member.invalidate_sessions', 'display_name' => '会员会话失效', 'group' => 'system.member', 'description' => '强制会员会话失效', 'sort' => 860],
            ['name' => 'system.file.view', 'display_name' => '文件查看', 'group' => 'system.file', 'description' => '查看文件', 'sort' => 940],
            ['name' => 'system.file.create', 'display_name' => '文件上传', 'group' => 'system.file', 'description' => '上传文件', 'sort' => 950],
            ['name' => 'system.file.delete', 'display_name' => '文件删除', 'group' => 'system.file', 'description' => '删除文件', 'sort' => 960],
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
            ['name' => 'system.activity-log.view', 'display_name' => '操作日志查看', 'group' => 'system.audit', 'description' => '查看后台操作与凭据变更日志', 'sort' => 710],
            ['name' => 'system.login-log.view', 'display_name' => '登录日志查看', 'group' => 'system.audit', 'description' => '查看管理员与会员认证日志', 'sort' => 720],
        ];
    }

    /**
     * @return array<int, array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}>
     */
    private function menuDefinitions(): array
    {
        return [
            ...$this->pageWithButtons('admin9.core.system.roles', 'system.roles', '角色管理', '/system/roles', 'system/roles/index', 'user-group', 'system.role', 20),
            ...$this->pageWithButtons('admin9.core.system.permissions', 'system.permissions', '权限管理', '/system/permissions', 'system/permissions/index', 'lock', 'system.permission', 25),
            ...$this->pageWithButtons('admin9.core.system.users', 'system.users', '用户管理', '/system/users', 'system/users/index', 'user', 'system.user', 30, ['assign-role' => '分配角色']),
            ...$this->memberPageWithButtons(),
            ...$this->filePageWithButtons(),
            ...$this->pageWithButtons('admin9.core.system.menus', 'system.menus', '菜单管理', '/system/menus', 'system/menus/index', 'menu', 'system.menu', 40),
            ...$this->pageWithButtons('admin9.core.system.dictionaries', 'system.dictionaries', '字典管理', '/system/dictionaries', 'system/dictionaries/index', 'book', 'system.dictionary', 50),
            ...$this->systemSettingsPage(),
        ];
    }

    /**
     * @return array<int, array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}>
     */
    private function systemSettingsPage(): array
    {
        return [
            [
                'seed_key' => 'admin9.core.system.settings',
                'parent_seed_key' => 'admin9.core.system',
                'code' => 'system.configs',
                'name' => '系统设置',
                'path' => '/system/configs',
                'component' => 'system/configs/index',
                'icon' => 'settings',
                'type' => Menu::TYPE_PAGE,
                'permission_names' => ['system.config.view'],
                'sort' => 60,
                'is_visible' => true,
                'is_active' => true,
            ],
            [
                'seed_key' => 'admin9.core.system.settings.update',
                'parent_seed_key' => 'admin9.core.system.settings',
                'code' => 'system.configs.update',
                'name' => '编辑',
                'path' => null,
                'component' => null,
                'icon' => null,
                'type' => Menu::TYPE_BUTTON,
                'permission_names' => ['system.config.update'],
                'sort' => 20,
                'is_visible' => false,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $extraButtons
     * @return array<int, array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}>
     */
    private function pageWithButtons(
        string $seedKey,
        string $code,
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
                'seed_key' => $seedKey,
                'parent_seed_key' => 'admin9.core.system',
                'code' => $code,
                'name' => $name,
                'path' => $path,
                'component' => $component,
                'icon' => $icon,
                'type' => Menu::TYPE_PAGE,
                'permission_names' => ["{$permissionPrefix}.view"],
                'sort' => $sort,
                'is_visible' => true,
                'is_active' => true,
            ],
            ...collect($buttons)
                ->map(fn (string $buttonName, string $action): array => [
                    'seed_key' => "{$seedKey}.{$action}",
                    'parent_seed_key' => $seedKey,
                    'code' => "{$code}.{$action}",
                    'name' => $buttonName,
                    'path' => null,
                    'component' => null,
                    'icon' => null,
                    'type' => Menu::TYPE_BUTTON,
                    'permission_names' => ["{$permissionPrefix}.{$action}"],
                    'sort' => match ($action) {
                        'create' => 10,
                        'update' => 20,
                        'delete' => 30,
                        default => 40,
                    },
                    'is_visible' => false,
                    'is_active' => true,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}>
     */
    private function memberPageWithButtons(): array
    {
        $buttons = [
            'create' => ['name' => '新增', 'sort' => 10],
            'update' => ['name' => '编辑', 'sort' => 20],
            'status' => ['name' => '启停', 'sort' => 30],
            'reset_password' => ['name' => '重置密码', 'sort' => 40],
            'invalidate_sessions' => ['name' => '会话失效', 'sort' => 50],
        ];

        return [
            [
                'seed_key' => 'admin9.core.system.members',
                'parent_seed_key' => 'admin9.core.system',
                'code' => 'SystemMember',
                'name' => '会员管理',
                'path' => '/system/members',
                'component' => 'system/members/index',
                'icon' => 'user-group',
                'type' => Menu::TYPE_PAGE,
                'permission_names' => ['system.member.view'],
                'sort' => 35,
                'is_visible' => true,
                'is_active' => true,
            ],
            ...collect($buttons)->map(fn (array $button, string $action): array => [
                'seed_key' => 'admin9.core.system.members.'.str_replace('_', '-', $action),
                'parent_seed_key' => 'admin9.core.system.members',
                'code' => "SystemMember.{$action}",
                'name' => $button['name'],
                'path' => null,
                'component' => null,
                'icon' => null,
                'type' => Menu::TYPE_BUTTON,
                'permission_names' => ["system.member.{$action}"],
                'sort' => $button['sort'],
                'is_visible' => false,
                'is_active' => true,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}>
     */
    private function filePageWithButtons(): array
    {
        return [
            [
                'seed_key' => 'admin9.core.system.files',
                'parent_seed_key' => 'admin9.core.system',
                'code' => 'system.file',
                'name' => '文件管理',
                'path' => '/system/file',
                'component' => 'system/file/index',
                'icon' => 'file',
                'type' => Menu::TYPE_PAGE,
                'permission_names' => ['system.file.view'],
                'sort' => 39,
                'is_visible' => true,
                'is_active' => true,
            ],
            [
                'seed_key' => 'admin9.core.system.files.create',
                'parent_seed_key' => 'admin9.core.system.files',
                'code' => 'system.file.create',
                'name' => '上传',
                'path' => null,
                'component' => null,
                'icon' => null,
                'type' => Menu::TYPE_BUTTON,
                'permission_names' => ['system.file.create'],
                'sort' => 10,
                'is_visible' => false,
                'is_active' => true,
            ],
            [
                'seed_key' => 'admin9.core.system.files.delete',
                'parent_seed_key' => 'admin9.core.system.files',
                'code' => 'system.file.delete',
                'name' => '删除',
                'path' => null,
                'component' => null,
                'icon' => null,
                'type' => Menu::TYPE_BUTTON,
                'permission_names' => ['system.file.delete'],
                'sort' => 20,
                'is_visible' => false,
                'is_active' => true,
            ],
        ];
    }
}
