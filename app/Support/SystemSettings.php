<?php

namespace App\Support;

use App\Models\File;
use App\Models\SystemConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class SystemSettings
{
    public const IDENTITY_GROUP = 'system.identity';

    public const BRANDING_GROUP = 'system.branding';

    public const SYSTEM_NAME_KEY = 'system.identity.name';

    public const COPYRIGHT_KEY = 'system.identity.copyright';

    public const ICP_FILING_NUMBER_KEY = 'system.identity.icp_filing_number';

    public const NAVIGATION_LOGO_KEY = 'system.branding.navigation_logo';

    public const LOGIN_LOGO_KEY = 'system.branding.login_logo';

    public const LOGIN_BACKGROUND_KEY = 'system.branding.login_background';

    public const FAVICON_KEY = 'system.branding.favicon';

    /**
     * @var array<string, string>
     */
    private const BASIC_FIELDS = [
        'system_name' => self::SYSTEM_NAME_KEY,
        'copyright' => self::COPYRIGHT_KEY,
        'icp_filing_number' => self::ICP_FILING_NUMBER_KEY,
    ];

    /**
     * @var array<string, string>
     */
    private const BRANDING_FIELDS = [
        'navigation_logo_path' => self::NAVIGATION_LOGO_KEY,
        'login_logo_path' => self::LOGIN_LOGO_KEY,
        'login_background_path' => self::LOGIN_BACKGROUND_KEY,
        'favicon_path' => self::FAVICON_KEY,
    ];

    /**
     * @return array<string, array{name: string, type: string, config_group: string, description: string, is_public: bool, is_active: bool, sort: int}>
     */
    public static function definitions(): array
    {
        return [
            self::SYSTEM_NAME_KEY => [
                'name' => '系统名称',
                'type' => SystemConfig::TYPE_STRING,
                'config_group' => self::IDENTITY_GROUP,
                'description' => '系统对外展示名称',
                'is_public' => true,
                'is_active' => true,
                'sort' => 10,
            ],
            self::COPYRIGHT_KEY => [
                'name' => '版权信息',
                'type' => SystemConfig::TYPE_TEXT,
                'config_group' => self::IDENTITY_GROUP,
                'description' => '系统页脚版权信息',
                'is_public' => true,
                'is_active' => true,
                'sort' => 20,
            ],
            self::ICP_FILING_NUMBER_KEY => [
                'name' => 'ICP备案号',
                'type' => SystemConfig::TYPE_STRING,
                'config_group' => self::IDENTITY_GROUP,
                'description' => 'ICP备案编号',
                'is_public' => true,
                'is_active' => true,
                'sort' => 30,
            ],
            self::NAVIGATION_LOGO_KEY => [
                'name' => '后台导航 Logo',
                'type' => SystemConfig::TYPE_STRING,
                'config_group' => self::BRANDING_GROUP,
                'description' => '后台导航使用的受管图片文件',
                'is_public' => true,
                'is_active' => true,
                'sort' => 40,
            ],
            self::LOGIN_LOGO_KEY => [
                'name' => '登录页 Logo',
                'type' => SystemConfig::TYPE_STRING,
                'config_group' => self::BRANDING_GROUP,
                'description' => '登录页使用的受管图片文件',
                'is_public' => true,
                'is_active' => true,
                'sort' => 50,
            ],
            self::LOGIN_BACKGROUND_KEY => [
                'name' => '登录页背景图',
                'type' => SystemConfig::TYPE_STRING,
                'config_group' => self::BRANDING_GROUP,
                'description' => '登录页使用的受管背景图片文件',
                'is_public' => true,
                'is_active' => true,
                'sort' => 60,
            ],
            self::FAVICON_KEY => [
                'name' => '浏览器图标',
                'type' => SystemConfig::TYPE_STRING,
                'config_group' => self::BRANDING_GROUP,
                'description' => '浏览器 Favicon 使用的受管图片文件',
                'is_public' => true,
                'is_active' => true,
                'sort' => 70,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function managedKeys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<int, string>
     */
    public static function brandingKeys(): array
    {
        return array_values(self::BRANDING_FIELDS);
    }

    public static function isManagedKey(?string $key): bool
    {
        return $key !== null && in_array($key, self::managedKeys(), true);
    }

    /**
     * @return array{basic: array{system_name: ?string, copyright: ?string, icp_filing_number: ?string}, branding: array<string, ?string>}
     */
    public function read(): array
    {
        /** @var Collection<string, SystemConfig> $configs */
        $configs = SystemConfig::query()
            ->whereIn('key', self::managedKeys())
            ->get()
            ->keyBy('key');
        $brandingPaths = collect(self::BRANDING_FIELDS)
            ->mapWithKeys(fn (string $key, string $pathField): array => [
                $pathField => $this->storedPath($configs->get($key)?->value),
            ])
            ->all();
        $paths = collect($brandingPaths)->filter()->unique()->values();
        /** @var Collection<string, File> $files */
        $files = File::query()
            ->where('disk', 'public')
            ->where('type', 'image')
            ->where('status', File::STATUS_READY)
            ->whereNull('deletion_token')
            ->whereIn('path', $paths)
            ->get()
            ->keyBy('path');
        $branding = [];

        foreach ($brandingPaths as $pathField => $path) {
            $assetName = str($pathField)->beforeLast('_path')->toString();
            $file = $path === null ? null : $files->get($path);
            $branding[$pathField] = $path;
            $branding["{$assetName}_url"] = $file === null
                ? null
                : Storage::disk($file->disk)->url($file->path);
        }

        return [
            'basic' => [
                'system_name' => $configs->get(self::SYSTEM_NAME_KEY)?->value,
                'copyright' => $configs->get(self::COPYRIGHT_KEY)?->value,
                'icp_filing_number' => $configs->get(self::ICP_FILING_NUMBER_KEY)?->value,
            ],
            'branding' => $branding,
        ];
    }

    /**
     * @param  array{system_name: string, copyright: ?string, icp_filing_number: ?string}  $values
     */
    public function updateBasic(array $values): void
    {
        DB::transaction(function () use ($values): void {
            $configs = $this->lockedConfigs(array_values(self::BASIC_FIELDS));

            foreach (self::BASIC_FIELDS as $field => $key) {
                $configs->get($key)?->update(['value' => $values[$field]]);
            }
        }, attempts: 3);
    }

    /**
     * @param  array{navigation_logo_path: ?string, login_logo_path: ?string, login_background_path: ?string, favicon_path: ?string}  $values
     */
    public function updateBranding(array $values): void
    {
        DB::transaction(function () use ($values): void {
            $values = $this->canonicalBrandingPaths($values);
            $configs = $this->lockedConfigs(array_values(self::BRANDING_FIELDS));

            foreach (self::BRANDING_FIELDS as $field => $key) {
                $configs->get($key)?->update([
                    'value' => $values[$field],
                ]);
            }
        }, attempts: 3);
    }

    /**
     * @param  array<int, string>  $keys
     * @return Collection<string, SystemConfig>
     */
    private function lockedConfigs(array $keys): Collection
    {
        /** @var Collection<string, SystemConfig> $configs */
        $configs = SystemConfig::query()
            ->whereIn('key', $keys)
            ->orderBy('key')
            ->lockForUpdate()
            ->get()
            ->keyBy('key');

        if ($configs->count() !== count($keys)) {
            throw new \LogicException('Managed system settings have not been initialized.');
        }

        return $configs;
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array<string, ?string>
     */
    private function canonicalBrandingPaths(array $values): array
    {
        $paths = collect($values)->filter()->unique()->values();
        /** @var Collection<int, File> $files */
        $files = File::query()
            ->where('disk', 'public')
            ->where('type', 'image')
            ->where('status', File::STATUS_READY)
            ->whereNull('deletion_token')
            ->whereIn('path', $paths)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $canonical = [];
        $errors = [];

        foreach ($values as $field => $path) {
            if ($path === null) {
                $canonical[$field] = null;

                continue;
            }

            $file = $files->first(fn (File $file): bool => strcasecmp($file->path, $path) === 0);

            if ($file === null) {
                $errors[$field][] = 'The selected file must be a ready public image.';

                continue;
            }

            $canonical[$field] = $file->path;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $canonical;
    }

    private function storedPath(?string $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
