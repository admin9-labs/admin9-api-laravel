<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Support\Admin\SeededMenuProvisioner;
use Illuminate\Database\Seeder;

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
        $warnings = app(SeededMenuProvisioner::class)->provision([[
            'seed_key' => 'admin9.core.system.logs',
            'parent_seed_key' => 'admin9.core.system',
            'code' => 'system.logs',
            'name' => '日志管理',
            'path' => '/system/log',
            'component' => 'system/log/index',
            'icon' => 'file',
            'type' => Menu::TYPE_PAGE,
            'permission_names' => self::PERMISSION_NAMES,
            'sort' => 70,
            'is_visible' => true,
            'is_active' => true,
        ]]);

        foreach ($warnings as $warning) {
            $this->command?->warn($warning);
        }
    }
}
