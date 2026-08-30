<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFINITIONS = [
        'system.branding.navigation_logo' => ['name' => '后台导航 Logo', 'description' => '后台导航使用的受管图片文件', 'sort' => 40],
        'system.branding.login_logo' => ['name' => '登录页 Logo', 'description' => '登录页使用的受管图片文件', 'sort' => 50],
        'system.branding.login_background' => ['name' => '登录页背景图', 'description' => '登录页使用的受管背景图片文件', 'sort' => 60],
        'system.branding.favicon' => ['name' => '浏览器图标', 'description' => '浏览器 Favicon 使用的受管图片文件', 'sort' => 70],
    ];

    private const LEGACY_KEYS = [
        'system.branding.navigation_logo_url',
        'system.branding.login_logo_url',
        'system.branding.login_background_url',
        'system.branding.favicon_url',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $existingSettings = $connection->table('system_configs')
                ->whereIn('key', array_keys(self::DEFINITIONS))
                ->get()
                ->keyBy('key');
            $legacySettingsExist = $connection->table('system_configs')
                ->whereIn('key', self::LEGACY_KEYS)
                ->exists();

            if ($existingSettings->isNotEmpty()) {
                if ($existingSettings->count() !== count(self::DEFINITIONS)
                    || $legacySettingsExist
                    || ! $this->settingsHaveExpectedState($existingSettings)) {
                    throw new RuntimeException('Existing branding file path settings conflict with this migration.');
                }

                return;
            }

            $timestamp = now();
            $rows = collect(self::DEFINITIONS)
                ->map(fn (array $definition, string $key): array => [
                    ...$definition,
                    'key' => $key,
                    'value' => null,
                    'type' => 'string',
                    'config_group' => 'system.branding',
                    'is_public' => true,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->values()
                ->all();

            $connection->table('system_configs')->insert($rows);

            $connection->table('system_configs')->whereIn('key', self::LEGACY_KEYS)->delete();

            $settings = $connection->table('system_configs')
                ->whereIn('key', array_keys(self::DEFINITIONS))
                ->get()
                ->keyBy('key');

            if ($settings->count() !== count(self::DEFINITIONS)
                || $connection->table('system_configs')->whereIn('key', self::LEGACY_KEYS)->exists()
                || ! $this->settingsHaveExpectedState($settings)) {
                throw new RuntimeException('Branding file path settings were not migrated completely.');
            }
        });
    }

    private function settingsHaveExpectedState(Collection $settings): bool
    {
        foreach (self::DEFINITIONS as $key => $definition) {
            $setting = $settings->get($key);

            if ($setting === null
                || $setting->name !== $definition['name']
                || $setting->description !== $definition['description']
                || (int) $setting->sort !== $definition['sort']
                || $setting->type !== 'string'
                || $setting->config_group !== 'system.branding'
                || ! (bool) $setting->is_public
                || ! (bool) $setting->is_active
                || ! $this->hasValidStoredPath($setting->value)) {
                return false;
            }
        }

        return true;
    }

    private function hasValidStoredPath(mixed $value): bool
    {
        return $value === null
            || (is_string($value)
                && preg_match('/\Afiles\/[0-9]{4}\/[0-9]{2}\/[0-9a-f-]+\.[a-z0-9]+\z/D', $value) === 1);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The discarded legacy URLs cannot be reconstructed safely from file paths.
    }
};
