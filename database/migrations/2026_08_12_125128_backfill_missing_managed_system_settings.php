<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $timestamp = now();
            $definitions = [
                'system.identity.name' => ['name' => '系统名称', 'type' => 'string', 'config_group' => 'system.identity', 'description' => '系统对外展示名称', 'is_public' => true, 'is_active' => true, 'sort' => 10],
                'system.identity.copyright' => ['name' => '版权信息', 'type' => 'text', 'config_group' => 'system.identity', 'description' => '系统页脚版权信息', 'is_public' => true, 'is_active' => true, 'sort' => 20],
                'system.identity.icp_filing_number' => ['name' => 'ICP备案号', 'type' => 'string', 'config_group' => 'system.identity', 'description' => 'ICP备案编号', 'is_public' => true, 'is_active' => true, 'sort' => 30],
                'system.branding.navigation_logo_media_id' => ['name' => '后台导航 Logo', 'type' => 'integer', 'config_group' => 'system.branding', 'description' => '后台导航使用的图片素材 ID', 'is_public' => true, 'is_active' => true, 'sort' => 40],
                'system.branding.login_logo_media_id' => ['name' => '登录页 Logo', 'type' => 'integer', 'config_group' => 'system.branding', 'description' => '登录页使用的 Logo 素材 ID', 'is_public' => true, 'is_active' => true, 'sort' => 50],
                'system.branding.login_background_media_id' => ['name' => '登录页背景图', 'type' => 'integer', 'config_group' => 'system.branding', 'description' => '登录页使用的背景图片素材 ID', 'is_public' => true, 'is_active' => true, 'sort' => 60],
                'system.branding.favicon_media_id' => ['name' => '浏览器图标', 'type' => 'integer', 'config_group' => 'system.branding', 'description' => '浏览器 Favicon 使用的图片素材 ID', 'is_public' => true, 'is_active' => true, 'sort' => 70],
            ];
            $rows = collect($definitions)
                ->map(fn (array $definition, string $key): array => [
                    ...$definition,
                    'key' => $key,
                    'value' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->values()
                ->all();

            $connection->table('system_configs')->upsert(
                $rows,
                ['key'],
                ['name', 'type', 'config_group', 'description', 'is_public', 'is_active', 'sort', 'updated_at'],
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve settings that may have been configured after this repair ran.
    }
};
