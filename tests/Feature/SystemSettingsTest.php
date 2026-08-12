<?php

namespace Tests\Feature;

use App\Exceptions\ManagedSystemSettingException;
use App\Exceptions\MediaInUseBySystemSettingsException;
use App\Models\Media;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\ApiRouting;
use App\Support\Auth\AccountInactiveException;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\FailingSecondActivity;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_public_settings_are_available_without_authentication_and_distinguish_media_states(): void
    {
        Storage::fake('public');
        $ready = Media::factory()->create();
        $notReady = Media::factory()->create(['status' => Media::STATUS_FAILED]);

        $this->setSettingValue(SystemSettings::SYSTEM_NAME_KEY, 'Admin9');
        $this->setSettingValue(SystemSettings::NAVIGATION_LOGO_MEDIA_ID_KEY, (string) $ready->id);
        $this->setSettingValue(SystemSettings::LOGIN_LOGO_MEDIA_ID_KEY, (string) $notReady->id);
        $this->setSettingValue(SystemSettings::LOGIN_BACKGROUND_MEDIA_ID_KEY, '999999');

        $this->getJson(ApiRouting::path('/system-settings/public'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.basic.system_name', 'Admin9')
            ->assertJsonPath('data.basic.copyright', null)
            ->assertJsonPath('data.basic.icp_filing_number', null)
            ->assertJsonPath('data.branding.navigation_logo.media_id', $ready->id)
            ->assertJsonPath('data.branding.navigation_logo.state', 'ready')
            ->assertJsonPath('data.branding.navigation_logo.media.id', $ready->id)
            ->assertJsonPath('data.branding.login_logo.media_id', $notReady->id)
            ->assertJsonPath('data.branding.login_logo.state', 'invalid')
            ->assertJsonPath('data.branding.login_logo.media', null)
            ->assertJsonPath('data.branding.login_background.media_id', 999999)
            ->assertJsonPath('data.branding.login_background.state', 'invalid')
            ->assertJsonPath('data.branding.favicon.media_id', null)
            ->assertJsonPath('data.branding.favicon.state', 'empty')
            ->assertJsonPath('data.branding.favicon.media', null)
            ->assertHeader('X-Request-Id');
    }

    public function test_admin_read_and_each_tab_update_require_the_exact_existing_permissions(): void
    {
        $this->createPermission('system.config.view');
        $this->createPermission('system.config.update');

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('system.config.view');
        $viewerHeaders = $this->authorizationHeader($this->adminTokenFor($viewer));

        $this->getJson(ApiRouting::path('/admin/system-settings'))->assertUnauthorized();
        $this->getJson(ApiRouting::path('/admin/system-settings'), $viewerHeaders)->assertOk();
        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), $this->basicPayload(), $viewerHeaders)
            ->assertForbidden();

        $updater = User::factory()->create();
        $updater->givePermissionTo('system.config.update');
        $updaterHeaders = $this->authorizationHeader($this->adminTokenFor($updater));

        $this->getJson(ApiRouting::path('/admin/system-settings'), $updaterHeaders)->assertForbidden();
        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $this->brandingPayload(), $updaterHeaders)
            ->assertOk();
    }

    public function test_all_admin_settings_routes_reject_an_inactive_admin(): void
    {
        $this->createPermission('system.config.view');
        $this->createPermission('system.config.update');

        foreach ([
            ['method' => 'get', 'path' => '/admin/system-settings', 'payload' => []],
            ['method' => 'put', 'path' => '/admin/system-settings/basic', 'payload' => $this->basicPayload()],
            ['method' => 'put', 'path' => '/admin/system-settings/branding', 'payload' => $this->brandingPayload()],
        ] as $route) {
            $admin = User::factory()->create();
            $admin->givePermissionTo(['system.config.view', 'system.config.update']);
            $headers = $this->authorizationHeader($this->adminTokenFor($admin));
            $admin->forceFill(['is_active' => false])->save();

            $this->json($route['method'], ApiRouting::path($route['path']), $route['payload'], $headers)
                ->assertForbidden()
                ->assertJsonPath('error_code', AccountInactiveException::ERROR_CODE);
        }
    }

    public function test_basic_tab_normalizes_values_updates_only_identity_and_keeps_eloquent_audit(): void
    {
        $headers = $this->settingsManagerHeaders();
        $media = Media::factory()->create();
        $this->setSettingValue(SystemSettings::FAVICON_MEDIA_ID_KEY, (string) $media->id);

        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), [
            'system_name' => '  Admin9 Pro  ',
            'copyright' => '   ',
            'icp_filing_number' => '  ICP 123  ',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.basic.system_name', 'Admin9 Pro')
            ->assertJsonPath('data.basic.copyright', null)
            ->assertJsonPath('data.basic.icp_filing_number', 'ICP 123')
            ->assertJsonPath('data.branding.favicon.media_id', $media->id)
            ->assertJsonPath('data.branding.favicon.state', 'ready');

        $this->assertSame('Admin9 Pro', $this->setting(SystemSettings::SYSTEM_NAME_KEY)->value);
        $this->assertNull($this->setting(SystemSettings::COPYRIGHT_KEY)->value);
        $this->assertSame('ICP 123', $this->setting(SystemSettings::ICP_FILING_NUMBER_KEY)->value);
        $this->assertSame((string) $media->id, $this->setting(SystemSettings::FAVICON_MEDIA_ID_KEY)->value);

        $auditedKeys = Activity::query()
            ->where('subject_type', (new SystemConfig)->getMorphClass())
            ->whereIn('subject_id', SystemConfig::query()
                ->whereIn('key', [SystemSettings::SYSTEM_NAME_KEY, SystemSettings::ICP_FILING_NUMBER_KEY])
                ->pluck('id'))
            ->get()
            ->pluck('properties.config_key')
            ->all();
        $this->assertContains(SystemSettings::SYSTEM_NAME_KEY, $auditedKeys);
        $this->assertContains(SystemSettings::ICP_FILING_NUMBER_KEY, $auditedKeys);
    }

    public function test_basic_tab_requires_complete_known_fields_and_a_non_blank_system_name(): void
    {
        $headers = $this->settingsManagerHeaders();

        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), [
            'system_name' => '   ',
            'copyright' => null,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['system_name', 'icp_filing_number']);

        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), [
            ...$this->basicPayload(),
            'key' => SystemSettings::SYSTEM_NAME_KEY,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');

        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), [
            ...$this->basicPayload(),
            'system_name' => str_repeat('a', 101),
            'copyright' => str_repeat('b', 1001),
            'icp_filing_number' => str_repeat('c', 101),
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['system_name', 'copyright', 'icp_filing_number']);
    }

    public function test_branding_tab_saves_ready_images_and_rejects_invalid_media(): void
    {
        $headers = $this->settingsManagerHeaders();
        $navigationLogo = Media::factory()->create();
        $loginLogo = Media::factory()->create();
        $failed = Media::factory()->create(['status' => Media::STATUS_FAILED]);
        $nonImage = Media::factory()->create(['mime_type' => 'application/pdf', 'extension' => 'pdf']);
        $deleting = Media::factory()->create(['deletion_token' => 'active-delete']);

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            'navigation_logo_media_id' => $navigationLogo->id,
            'login_logo_media_id' => $loginLogo->id,
            'login_background_media_id' => null,
            'favicon_media_id' => null,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo.media.id', $navigationLogo->id)
            ->assertJsonPath('data.branding.login_logo.media.id', $loginLogo->id)
            ->assertJsonPath('data.branding.login_background.state', 'empty');

        foreach ([$failed, $nonImage, $deleting] as $invalidMedia) {
            $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
                ...$this->brandingPayload(),
                'favicon_media_id' => $invalidMedia->id,
            ], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('favicon_media_id');
        }

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            ...$this->brandingPayload(),
            'favicon_media_id' => 999999,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('favicon_media_id');

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            ...$this->brandingPayload(),
            'config_group' => SystemSettings::BRANDING_GROUP,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config_group');
    }

    public function test_each_tab_rolls_back_as_one_transaction_when_an_eloquent_update_fails(): void
    {
        $headers = $this->settingsManagerHeaders();

        foreach ([
            [
                'path' => '/admin/system-settings/basic',
                'payload' => ['system_name' => 'After', 'copyright' => 'After', 'icp_filing_number' => 'After'],
                'keys' => [SystemSettings::SYSTEM_NAME_KEY, SystemSettings::COPYRIGHT_KEY, SystemSettings::ICP_FILING_NUMBER_KEY],
            ],
            [
                'path' => '/admin/system-settings/branding',
                'payload' => array_fill_keys([
                    'navigation_logo_media_id',
                    'login_logo_media_id',
                    'login_background_media_id',
                    'favicon_media_id',
                ], Media::factory()->create()->id),
                'keys' => SystemSettings::brandingMediaKeys(),
            ],
        ] as $case) {
            SystemConfig::query()->whereIn('key', $case['keys'])->update(['value' => null]);
            $activityCountBefore = Activity::query()->count();
            FailingSecondActivity::reset();
            config(['activitylog.activity_model' => FailingSecondActivity::class]);

            $this->withoutExceptionHandling();
            try {
                $this->putJson(ApiRouting::path($case['path']), $case['payload'], $headers);
                $this->fail('Expected the second activity log write to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Second activity log write failed', $exception->getMessage());
            }

            $this->assertSame(
                [],
                SystemConfig::query()->whereIn('key', $case['keys'])->whereNotNull('value')->pluck('value')->all(),
            );
            $this->assertSame(2, FailingSecondActivity::$saveCount);
            $this->assertSame($activityCountBefore, Activity::query()->count());
        }
    }

    public function test_all_managed_keys_reject_generic_create_update_and_delete_with_one_conflict_code(): void
    {
        $headers = $this->genericConfigManagerHeaders();

        foreach (SystemSettings::managedKeys() as $key) {
            $config = $this->setting($key);

            $this->postJson(ApiRouting::path('/admin/system-configs'), [
                'name' => 'Bypass',
                'key' => $key,
                'value' => 'bypass',
            ], $headers)
                ->assertConflict()
                ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

            $this->patchJson(ApiRouting::path('/admin/system-configs/').$config->id, [
                'value' => 'bypass',
            ], $headers)
                ->assertConflict()
                ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

            $this->patchJson(ApiRouting::path('/admin/system-configs/').$config->id, [
                'name' => 'Bypass',
                'key' => 'renamed.'.$config->id,
                'type' => SystemConfig::TYPE_JSON,
                'config_group' => 'bypass',
                'description' => 'bypass',
                'is_public' => false,
                'is_active' => false,
                'sort' => 999,
            ], $headers)
                ->assertConflict()
                ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

            $this->deleteJson(ApiRouting::path('/admin/system-configs/').$config->id, [], $headers)
                ->assertConflict()
                ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

            $this->assertSame($key, $config->refresh()->key);
            $this->assertNull($config->value);
        }

        $ordinary = SystemConfig::factory()->create(['key' => 'custom.setting']);
        $this->patchJson(ApiRouting::path('/admin/system-configs/').$ordinary->id, ['value' => 'changed'], $headers)
            ->assertOk()
            ->assertJsonPath('data.system_config.value', 'changed');
        $this->deleteJson(ApiRouting::path('/admin/system-configs/').$ordinary->id, [], $headers)->assertOk();
        $this->assertModelMissing($ordinary);
    }

    public function test_generic_update_cannot_rename_an_ordinary_config_to_a_reserved_key(): void
    {
        $headers = $this->genericConfigManagerHeaders();
        $ordinary = SystemConfig::factory()->create(['key' => 'custom.setting']);

        $this->patchJson(ApiRouting::path('/admin/system-configs/').$ordinary->id, [
            'key' => SystemSettings::SYSTEM_NAME_KEY,
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

        $this->assertSame('custom.setting', $ordinary->refresh()->key);
    }

    public function test_generic_create_rejects_a_reserved_key_even_when_its_managed_row_is_missing(): void
    {
        $headers = $this->genericConfigManagerHeaders();
        $this->setting(SystemSettings::FAVICON_MEDIA_ID_KEY)->deleteQuietly();

        $this->postJson(ApiRouting::path('/admin/system-configs'), [
            'name' => 'Bypass',
            'key' => SystemSettings::FAVICON_MEDIA_ID_KEY,
            'value' => '1',
            'type' => SystemConfig::TYPE_INTEGER,
            'config_group' => SystemSettings::BRANDING_GROUP,
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

        $this->assertDatabaseMissing('system_configs', ['key' => SystemSettings::FAVICON_MEDIA_ID_KEY]);
    }

    public function test_referenced_media_cannot_be_deleted_even_when_the_setting_metadata_is_inactive(): void
    {
        Storage::fake('public');
        $media = Media::factory()->create();
        Storage::disk('public')->put($media->path, 'image bytes');
        $this->setting(SystemSettings::LOGIN_BACKGROUND_MEDIA_ID_KEY)->forceFill([
            'value' => (string) $media->id,
            'is_active' => false,
        ])->saveQuietly();
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.media.delete']));

        $this->deleteJson(ApiRouting::path('/admin/media/').$media->id, [], $headers)
            ->assertConflict()
            ->assertJsonPath('error_code', MediaInUseBySystemSettingsException::ERROR_CODE);

        $this->assertModelExists($media);
        $this->assertNull($media->refresh()->deletion_token);
        Storage::disk('public')->assertExists($media->path);
        $this->assertFalse(Activity::query()->where('event', 'media_deleted')->exists());
    }

    public function test_initialization_migration_preserves_values_and_rewrites_managed_metadata_idempotently(): void
    {
        $configured = $this->setting(SystemSettings::SYSTEM_NAME_KEY);
        $configured->forceFill([
            'value' => 'Configured Name',
            'name' => 'Wrong',
            'type' => SystemConfig::TYPE_JSON,
            'config_group' => 'wrong',
            'description' => 'Wrong',
            'is_public' => false,
            'is_active' => false,
            'sort' => 999,
        ])->saveQuietly();
        $this->setting(SystemSettings::FAVICON_MEDIA_ID_KEY)->deleteQuietly();
        SystemConfig::factory()->create(['key' => 'site.title', 'value' => 'Legacy Name']);

        $migration = require database_path('migrations/2026_08_12_085238_initialize_managed_system_settings.php');
        $migration->up();
        $migration->up();

        $this->assertSame(count(SystemSettings::managedKeys()), SystemConfig::query()->whereIn('key', SystemSettings::managedKeys())->count());
        $this->assertDatabaseMissing('system_configs', ['key' => 'site.title']);
        $configured->refresh();
        $definition = SystemSettings::definitions()[SystemSettings::SYSTEM_NAME_KEY];
        $this->assertSame('Configured Name', $configured->value);
        $this->assertSame($definition['name'], $configured->name);
        $this->assertSame($definition['type'], $configured->type);
        $this->assertSame($definition['config_group'], $configured->config_group);
        $this->assertSame($definition['description'], $configured->description);
        $this->assertSame($definition['is_public'], $configured->is_public);
        $this->assertSame($definition['is_active'], $configured->is_active);
        $this->assertSame($definition['sort'], $configured->sort);
    }

    public function test_initialization_migration_moves_the_legacy_title_when_the_managed_name_is_missing(): void
    {
        $this->setting(SystemSettings::SYSTEM_NAME_KEY)->deleteQuietly();
        SystemConfig::factory()->create([
            'key' => 'site.title',
            'value' => 'Legacy Name',
        ]);

        $migration = require database_path('migrations/2026_08_12_085238_initialize_managed_system_settings.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseMissing('system_configs', ['key' => 'site.title']);
        $this->assertSame('Legacy Name', $this->setting(SystemSettings::SYSTEM_NAME_KEY)->value);
    }

    public function test_forward_fix_restores_missing_managed_settings_without_overwriting_values(): void
    {
        $configured = $this->setting(SystemSettings::SYSTEM_NAME_KEY);
        $createdAt = now()->subDay()->startOfSecond();
        $configured->forceFill([
            'value' => 'Configured Name',
            'name' => 'Wrong',
            'type' => SystemConfig::TYPE_JSON,
            'config_group' => 'wrong',
            'description' => 'Wrong',
            'is_public' => false,
            'is_active' => false,
            'sort' => 999,
            'created_at' => $createdAt,
        ])->saveQuietly();
        SystemConfig::query()
            ->whereIn('key', array_diff(SystemSettings::managedKeys(), [SystemSettings::SYSTEM_NAME_KEY]))
            ->delete();

        $migration = require database_path('migrations/2026_08_12_125128_backfill_missing_managed_system_settings.php');
        $migration->up();
        $migration->up();

        $this->assertSame(
            count(SystemSettings::managedKeys()),
            SystemConfig::query()->whereIn('key', SystemSettings::managedKeys())->count(),
        );
        $this->assertSame(
            SystemSettings::managedKeys(),
            SystemConfig::query()
                ->whereIn('key', SystemSettings::managedKeys())
                ->orderBy('sort')
                ->pluck('key')
                ->all(),
        );
        $configured->refresh();
        $this->assertSame('Configured Name', $configured->value);
        $this->assertTrue($configured->created_at->equalTo($createdAt));

        foreach (SystemSettings::definitions() as $key => $definition) {
            $setting = $this->setting($key);
            $this->assertSame($definition['name'], $setting->name);
            $this->assertSame($definition['type'], $setting->type);
            $this->assertSame($definition['config_group'], $setting->config_group);
            $this->assertSame($definition['description'], $setting->description);
            $this->assertSame($definition['is_public'], $setting->is_public);
            $this->assertSame($definition['is_active'], $setting->is_active);
            $this->assertSame($definition['sort'], $setting->sort);
        }
    }

    public function test_forward_fix_restores_all_managed_settings_idempotently(): void
    {
        SystemConfig::query()->whereIn('key', SystemSettings::managedKeys())->delete();

        $migration = require database_path('migrations/2026_08_12_125128_backfill_missing_managed_system_settings.php');
        $migration->up();
        $migration->up();

        $keys = SystemConfig::query()
            ->whereIn('key', SystemSettings::managedKeys())
            ->orderBy('sort')
            ->pluck('key');

        $this->assertCount(7, $keys);
        $this->assertSame(7, $keys->unique()->count());
        $this->assertSame(SystemSettings::managedKeys(), $keys->all());
    }

    /**
     * @return array{system_name: string, copyright: ?string, icp_filing_number: ?string}
     */
    private function basicPayload(): array
    {
        return [
            'system_name' => 'Admin9',
            'copyright' => null,
            'icp_filing_number' => null,
        ];
    }

    /**
     * @return array{navigation_logo_media_id: ?int, login_logo_media_id: ?int, login_background_media_id: ?int, favicon_media_id: ?int}
     */
    private function brandingPayload(?int $mediaId = null): array
    {
        return [
            'navigation_logo_media_id' => $mediaId,
            'login_logo_media_id' => null,
            'login_background_media_id' => null,
            'favicon_media_id' => null,
        ];
    }

    private function setting(string $key): SystemConfig
    {
        return SystemConfig::query()->where('key', $key)->firstOrFail();
    }

    private function setSettingValue(string $key, ?string $value): void
    {
        $this->setting($key)->forceFill(['value' => $value])->saveQuietly();
    }

    /**
     * @return array<string, string>
     */
    private function settingsManagerHeaders(): array
    {
        return $this->authorizationHeader($this->managerTokenFor([
            'system.config.view',
            'system.config.update',
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function genericConfigManagerHeaders(): array
    {
        return $this->authorizationHeader($this->managerTokenFor([
            'system.config.create',
            'system.config.update',
            'system.config.delete',
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
