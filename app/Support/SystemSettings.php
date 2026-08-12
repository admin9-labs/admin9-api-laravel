<?php

namespace App\Support;

use App\Models\Media;
use App\Models\SystemConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SystemSettings
{
    public const IDENTITY_GROUP = 'system.identity';

    public const BRANDING_GROUP = 'system.branding';

    public const SYSTEM_NAME_KEY = 'system.identity.name';

    public const COPYRIGHT_KEY = 'system.identity.copyright';

    public const ICP_FILING_NUMBER_KEY = 'system.identity.icp_filing_number';

    public const NAVIGATION_LOGO_MEDIA_ID_KEY = 'system.branding.navigation_logo_media_id';

    public const LOGIN_LOGO_MEDIA_ID_KEY = 'system.branding.login_logo_media_id';

    public const LOGIN_BACKGROUND_MEDIA_ID_KEY = 'system.branding.login_background_media_id';

    public const FAVICON_MEDIA_ID_KEY = 'system.branding.favicon_media_id';

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
        'navigation_logo_media_id' => self::NAVIGATION_LOGO_MEDIA_ID_KEY,
        'login_logo_media_id' => self::LOGIN_LOGO_MEDIA_ID_KEY,
        'login_background_media_id' => self::LOGIN_BACKGROUND_MEDIA_ID_KEY,
        'favicon_media_id' => self::FAVICON_MEDIA_ID_KEY,
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
            self::NAVIGATION_LOGO_MEDIA_ID_KEY => [
                'name' => '后台导航 Logo',
                'type' => SystemConfig::TYPE_INTEGER,
                'config_group' => self::BRANDING_GROUP,
                'description' => '后台导航使用的图片素材 ID',
                'is_public' => true,
                'is_active' => true,
                'sort' => 40,
            ],
            self::LOGIN_LOGO_MEDIA_ID_KEY => [
                'name' => '登录页 Logo',
                'type' => SystemConfig::TYPE_INTEGER,
                'config_group' => self::BRANDING_GROUP,
                'description' => '登录页使用的 Logo 素材 ID',
                'is_public' => true,
                'is_active' => true,
                'sort' => 50,
            ],
            self::LOGIN_BACKGROUND_MEDIA_ID_KEY => [
                'name' => '登录页背景图',
                'type' => SystemConfig::TYPE_INTEGER,
                'config_group' => self::BRANDING_GROUP,
                'description' => '登录页使用的背景图片素材 ID',
                'is_public' => true,
                'is_active' => true,
                'sort' => 60,
            ],
            self::FAVICON_MEDIA_ID_KEY => [
                'name' => '浏览器图标',
                'type' => SystemConfig::TYPE_INTEGER,
                'config_group' => self::BRANDING_GROUP,
                'description' => '浏览器 Favicon 使用的图片素材 ID',
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
    public static function brandingMediaKeys(): array
    {
        return array_values(self::BRANDING_FIELDS);
    }

    public static function isManagedKey(?string $key): bool
    {
        return $key !== null && in_array($key, self::managedKeys(), true);
    }

    /**
     * @return array{basic: array{system_name: ?string, copyright: ?string, icp_filing_number: ?string}, branding: array<string, array{media_id: ?int, state: string, media: ?Media}>}
     */
    public function read(): array
    {
        /** @var Collection<string, SystemConfig> $configs */
        $configs = SystemConfig::query()
            ->whereIn('key', self::managedKeys())
            ->get()
            ->keyBy('key');

        $mediaIds = collect(self::BRANDING_FIELDS)
            ->map(fn (string $key): ?int => $this->mediaId($configs->get($key)?->value))
            ->filter()
            ->unique()
            ->values();
        /** @var Collection<int, Media> $media */
        $media = Media::query()->whereKey($mediaIds)->get()->keyBy('id');

        return [
            'basic' => [
                'system_name' => $configs->get(self::SYSTEM_NAME_KEY)?->value,
                'copyright' => $configs->get(self::COPYRIGHT_KEY)?->value,
                'icp_filing_number' => $configs->get(self::ICP_FILING_NUMBER_KEY)?->value,
            ],
            'branding' => collect(self::BRANDING_FIELDS)
                ->mapWithKeys(function (string $key, string $requestField) use ($configs, $media): array {
                    $responseField = str($requestField)->beforeLast('_media_id')->toString();

                    return [$responseField => $this->mediaSetting($configs->get($key)?->value, $media)];
                })
                ->all(),
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
     * @param  array{navigation_logo_media_id: ?int, login_logo_media_id: ?int, login_background_media_id: ?int, favicon_media_id: ?int}  $values
     */
    public function updateBranding(array $values): void
    {
        DB::transaction(function () use ($values): void {
            $this->lockAndValidateMedia($values);
            $configs = $this->lockedConfigs(array_values(self::BRANDING_FIELDS));

            foreach (self::BRANDING_FIELDS as $field => $key) {
                $configs->get($key)?->update([
                    'value' => $values[$field] === null ? null : (string) $values[$field],
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
     * @param  array<string, ?int>  $values
     */
    private function lockAndValidateMedia(array $values): void
    {
        $ids = collect($values)->filter()->unique()->sort()->values();
        /** @var Collection<int, Media> $media */
        $media = Media::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $errors = [];

        foreach ($values as $field => $mediaId) {
            if ($mediaId !== null && ! $this->isReadyImage($media->get($mediaId))) {
                $errors[$field][] = 'The selected media must be a ready image that is not being deleted.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Collection<int, Media>  $media
     * @return array{media_id: ?int, state: string, media: ?Media}
     */
    private function mediaSetting(?string $value, Collection $media): array
    {
        if ($value === null || $value === '') {
            return ['media_id' => null, 'state' => 'empty', 'media' => null];
        }

        $mediaId = $this->mediaId($value);
        $model = $mediaId === null ? null : $media->get($mediaId);

        if (! $this->isReadyImage($model)) {
            return ['media_id' => $mediaId, 'state' => 'invalid', 'media' => null];
        }

        return ['media_id' => $mediaId, 'state' => 'ready', 'media' => $model];
    }

    private function mediaId(?string $value): ?int
    {
        if ($value === null || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function isReadyImage(?Media $media): bool
    {
        return $media instanceof Media
            && $media->status === Media::STATUS_READY
            && $media->deletion_token === null
            && str_starts_with($media->mime_type, 'image/');
    }
}
