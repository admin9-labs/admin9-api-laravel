<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdminAuditLogMenuSeeder extends Seeder
{
    private const PERMISSION_NAMES = [
        'system.activity-log.view',
        'system.login-log.view',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $systemMenu = Menu::query()
                ->where('code', 'system')
                ->first();

            if (! $systemMenu instanceof Menu) {
                throw new LogicException('Cannot seed admin audit log menu: required parent menu [system] is missing.');
            }

            $permissions = Permission::query()
                ->where('guard_name', 'admin')
                ->whereIn('name', self::PERMISSION_NAMES)
                ->get()
                ->keyBy('name');
            $missingPermissions = collect(self::PERMISSION_NAMES)
                ->reject(fn (string $permissionName): bool => $permissions->has($permissionName))
                ->values();

            if ($missingPermissions->isNotEmpty()) {
                throw new LogicException(sprintf(
                    'Cannot seed admin audit log menu: required admin permission(s) [%s] are missing.',
                    $missingPermissions->implode(', '),
                ));
            }

            $menu = Menu::query()->updateOrCreate(
                ['code' => 'system.logs'],
                [
                    'parent_id' => $systemMenu->id,
                    'name' => '日志管理',
                    'path' => '/system/log',
                    'component' => 'system/log/index',
                    'icon' => 'file',
                    'type' => Menu::TYPE_PAGE,
                    'sort' => 70,
                    'is_visible' => true,
                    'is_active' => true,
                ],
            );

            $menu->permissions()->sync(
                collect(self::PERMISSION_NAMES)
                    ->map(fn (string $permissionName): int => (int) $permissions->get($permissionName)->getKey())
                    ->all(),
            );
        });
    }
}
