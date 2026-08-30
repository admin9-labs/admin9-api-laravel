<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const DEFINITIONS = [
        'system.branding.navigation_logo_url' => ['name' => '后台导航 Logo URL', 'description' => '后台导航使用的 Logo URL', 'sort' => 40],
        'system.branding.login_logo_url' => ['name' => '登录页 Logo URL', 'description' => '登录页使用的 Logo URL', 'sort' => 50],
        'system.branding.login_background_url' => ['name' => '登录页背景图 URL', 'description' => '登录页使用的背景图片 URL', 'sort' => 60],
        'system.branding.favicon_url' => ['name' => '浏览器图标 URL', 'description' => '浏览器 Favicon URL', 'sort' => 70],
    ];

    private const PATH_TO_URL_KEYS = [
        'system.branding.navigation_logo' => 'system.branding.navigation_logo_url',
        'system.branding.login_logo' => 'system.branding.login_logo_url',
        'system.branding.login_background' => 'system.branding.login_background_url',
        'system.branding.favicon' => 'system.branding.favicon_url',
    ];

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $urlSettings = $connection->table('system_configs')
                ->whereIn('key', array_keys(self::DEFINITIONS))
                ->get()
                ->keyBy('key');
            $pathSettings = $connection->table('system_configs')
                ->whereIn('key', array_keys(self::PATH_TO_URL_KEYS))
                ->get()
                ->keyBy('key');

            if ($urlSettings->isNotEmpty()) {
                if ($urlSettings->count() !== count(self::DEFINITIONS)
                    || $pathSettings->isNotEmpty()
                    || ! $this->urlSettingsHaveExpectedState($urlSettings)) {
                    throw new RuntimeException('Existing branding URL settings conflict with this migration.');
                }

                return;
            }

            if ($pathSettings->count() !== count(self::PATH_TO_URL_KEYS)
                || ! $this->pathSettingsHaveExpectedState($pathSettings)) {
                throw new RuntimeException('Branding file path settings are incomplete or invalid.');
            }

            $paths = $pathSettings
                ->pluck('value')
                ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
                ->unique()
                ->values();
            $files = $connection->table('files')
                ->where('disk', 'public')
                ->where('type', 'image')
                ->where('status', 'ready')
                ->whereNull('deletion_token')
                ->whereIn('path', $paths)
                ->get()
                ->keyBy('path');
            $timestamp = now();
            $rows = [];

            foreach (self::PATH_TO_URL_KEYS as $pathKey => $urlKey) {
                $path = $pathSettings->get($pathKey)?->value;
                $file = is_string($path) && $path !== '' ? $files->get($path) : null;
                $url = null;

                if ($file !== null) {
                    $candidate = Storage::disk($file->disk)->url($file->path);
                    $url = $this->isSafeHttpUrl($candidate) ? $candidate : null;
                }

                $rows[] = [
                    ...self::DEFINITIONS[$urlKey],
                    'key' => $urlKey,
                    'value' => $url,
                    'type' => 'string',
                    'config_group' => 'system.branding',
                    'is_public' => true,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            $connection->table('system_configs')->insert($rows);
            $connection->table('system_configs')->whereIn('key', array_keys(self::PATH_TO_URL_KEYS))->delete();

            $restoredSettings = $connection->table('system_configs')
                ->whereIn('key', array_keys(self::DEFINITIONS))
                ->get()
                ->keyBy('key');

            if ($restoredSettings->count() !== count(self::DEFINITIONS)
                || $connection->table('system_configs')->whereIn('key', array_keys(self::PATH_TO_URL_KEYS))->exists()
                || ! $this->urlSettingsHaveExpectedState($restoredSettings)) {
                throw new RuntimeException('Branding URL settings were not restored completely.');
            }
        });
    }

    private function pathSettingsHaveExpectedState(Collection $settings): bool
    {
        foreach (array_keys(self::PATH_TO_URL_KEYS) as $key) {
            $setting = $settings->get($key);

            if ($setting === null
                || $setting->type !== 'string'
                || $setting->config_group !== 'system.branding'
                || ! (bool) $setting->is_public
                || ! (bool) $setting->is_active
                || ! $this->isStoredPath($setting->value)) {
                return false;
            }
        }

        return true;
    }

    private function urlSettingsHaveExpectedState(Collection $settings): bool
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
                || ($setting->value !== null && ! $this->isSafeHttpUrl($setting->value))) {
                return false;
            }
        }

        return true;
    }

    private function isStoredPath(mixed $value): bool
    {
        return $value === null
            || (is_string($value)
                && preg_match('/\Afiles\/[0-9]{4}\/[0-9]{2}\/[0-9a-f-]+\.[a-z0-9]+\z/D', $value) === 1);
    }

    private function isSafeHttpUrl(mixed $value): bool
    {
        if (! is_string($value) || Str::length($value) > 2048 || ! Str::isUrl($value, ['http', 'https'])) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    public function down(): void
    {
        // Direct URLs cannot be converted back to managed file paths safely.
    }
};
