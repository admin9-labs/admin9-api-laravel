<?php

namespace Tests\Feature;

use App\Exceptions\ManagedSystemSettingException;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\ApiRouting;
use App\Support\Auth\AccountInactiveException;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\FailingSecondActivity;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_public_settings_are_available_without_authentication_with_direct_branding_urls(): void
    {
        $this->setSettingValue(SystemSettings::SYSTEM_NAME_KEY, 'Admin9');
        $this->setSettingValue(SystemSettings::NAVIGATION_LOGO_URL_KEY, 'https://cdn.example.test/navigation.svg');
        $this->setSettingValue(SystemSettings::LOGIN_LOGO_URL_KEY, null);
        $this->setSettingValue(SystemSettings::LOGIN_BACKGROUND_URL_KEY, 'https://cdn.example.test/background.jpg');
        $this->setSettingValue(SystemSettings::FAVICON_URL_KEY, 'https://cdn.example.test/favicon.ico');

        $this->getJson(ApiRouting::path('/system-settings/public'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.basic.system_name', 'Admin9')
            ->assertJsonPath('data.basic.copyright', null)
            ->assertJsonPath('data.basic.icp_filing_number', null)
            ->assertJsonPath('data.branding.navigation_logo_url', 'https://cdn.example.test/navigation.svg')
            ->assertJsonPath('data.branding.login_logo_url', null)
            ->assertJsonPath('data.branding.login_background_url', 'https://cdn.example.test/background.jpg')
            ->assertJsonPath('data.branding.favicon_url', 'https://cdn.example.test/favicon.ico')
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
        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $this->brandingPayload(), $viewerHeaders)
            ->assertForbidden();

        $updater = User::factory()->create();
        $updater->givePermissionTo('system.config.update');
        $updaterHeaders = $this->authorizationHeader($this->adminTokenFor($updater));

        $this->getJson(ApiRouting::path('/admin/system-settings'), $updaterHeaders)->assertForbidden();
        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), $this->basicPayload(), $updaterHeaders)
            ->assertOk();
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

    public function test_basic_settings_normalize_values_update_only_identity_and_keep_eloquent_audit(): void
    {
        $headers = $this->settingsManagerHeaders();
        $brandingValues = [
            SystemSettings::NAVIGATION_LOGO_URL_KEY => 'https://cdn.example.test/navigation.svg',
            SystemSettings::LOGIN_LOGO_URL_KEY => 'https://cdn.example.test/login.svg',
            SystemSettings::LOGIN_BACKGROUND_URL_KEY => 'https://cdn.example.test/background.jpg',
            SystemSettings::FAVICON_URL_KEY => 'https://cdn.example.test/favicon.ico',
        ];

        foreach ($brandingValues as $key => $value) {
            $this->setSettingValue($key, $value);
        }

        $this->putJson(ApiRouting::path('/admin/system-settings/basic'), [
            'system_name' => '  Admin9 Pro  ',
            'copyright' => '   ',
            'icp_filing_number' => '  ICP 123  ',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.basic.system_name', 'Admin9 Pro')
            ->assertJsonPath('data.basic.copyright', null)
            ->assertJsonPath('data.basic.icp_filing_number', 'ICP 123')
            ->assertJsonPath('data.branding.navigation_logo_url', $brandingValues[SystemSettings::NAVIGATION_LOGO_URL_KEY])
            ->assertJsonPath('data.branding.login_logo_url', $brandingValues[SystemSettings::LOGIN_LOGO_URL_KEY])
            ->assertJsonPath('data.branding.login_background_url', $brandingValues[SystemSettings::LOGIN_BACKGROUND_URL_KEY])
            ->assertJsonPath('data.branding.favicon_url', $brandingValues[SystemSettings::FAVICON_URL_KEY]);

        $this->assertSame('Admin9 Pro', $this->setting(SystemSettings::SYSTEM_NAME_KEY)->value);
        $this->assertNull($this->setting(SystemSettings::COPYRIGHT_KEY)->value);
        $this->assertSame('ICP 123', $this->setting(SystemSettings::ICP_FILING_NUMBER_KEY)->value);
        foreach ($brandingValues as $key => $value) {
            $this->assertSame($value, $this->setting($key)->value);
        }

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

    public function test_basic_settings_require_complete_known_fields_and_a_non_blank_system_name(): void
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

    public function test_branding_settings_accept_valid_urls_and_clear_values(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.config.update']));
        $urlPrefix = 'https://cdn.example.test/';
        $maximumLengthUrl = $urlPrefix.str_repeat('a', 2048 - strlen($urlPrefix));
        $payload = [
            'navigation_logo_url' => 'http://cdn.example.test/logo.svg',
            'login_logo_url' => $maximumLengthUrl,
            'login_background_url' => null,
            'favicon_url' => 'https://cdn.example.test/favicon.ico',
        ];

        $this->assertSame(2048, strlen($maximumLengthUrl));

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo_url', $payload['navigation_logo_url'])
            ->assertJsonPath('data.branding.login_logo_url', $payload['login_logo_url'])
            ->assertJsonPath('data.branding.login_background_url', null)
            ->assertJsonPath('data.branding.favicon_url', $payload['favicon_url']);

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            'navigation_logo_url' => 'not-a-url',
            'login_logo_url' => null,
            'login_background_url' => null,
            'favicon_url' => null,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('navigation_logo_url');

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            'navigation_logo_url' => null,
            'login_logo_url' => null,
            'login_background_url' => null,
            'favicon_url' => null,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo_url', null)
            ->assertJsonPath('data.branding.login_logo_url', null)
            ->assertJsonPath('data.branding.login_background_url', null)
            ->assertJsonPath('data.branding.favicon_url', null);

        foreach (SystemSettings::brandingKeys() as $key) {
            $this->assertNull($this->setting($key)->value);
        }
    }

    public function test_branding_settings_reject_unsafe_urls_and_path_fields(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.config.update']));
        $payload = [
            'navigation_logo_url' => null,
            'login_logo_url' => null,
            'login_background_url' => null,
            'favicon_url' => null,
        ];

        foreach ([
            'ftp://files.example.test/logo.png',
            'https://user:pass@files.example.test/logo.png',
            'https://files.example.test/'.str_repeat('a', 2023),
        ] as $invalidUrl) {
            $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
                ...$payload,
                'favicon_url' => $invalidUrl,
            ], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('favicon_url');
        }

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            ...$payload,
            'favicon_path' => 'files/2026/08/favicon.png',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('favicon_path');
    }

    public function test_each_settings_tab_rolls_back_as_one_transaction_when_an_eloquent_update_fails(): void
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
                'payload' => [
                    'navigation_logo_url' => 'https://cdn.example.test/navigation.svg',
                    'login_logo_url' => 'https://cdn.example.test/login.svg',
                    'login_background_url' => 'https://cdn.example.test/background.jpg',
                    'favicon_url' => 'https://cdn.example.test/favicon.ico',
                ],
                'keys' => SystemSettings::brandingKeys(),
            ],
        ] as $case) {
            $originalValues = SystemConfig::query()
                ->whereIn('key', $case['keys'])
                ->orderBy('key')
                ->pluck('value', 'key')
                ->all();
            $activityCountBefore = Activity::query()->count();
            FailingSecondActivity::reset();
            config(['activitylog.activity_model' => FailingSecondActivity::class]);
            Exceptions::fake([RuntimeException::class]);

            $this->putJson(ApiRouting::path($case['path']), $case['payload'], $headers)
                ->assertStatus(500);

            $this->assertSame(
                $originalValues,
                SystemConfig::query()
                    ->whereIn('key', $case['keys'])
                    ->orderBy('key')
                    ->pluck('value', 'key')
                    ->all(),
            );
            $this->assertSame($activityCountBefore, Activity::query()->count());
            Exceptions::assertReported(RuntimeException::class);
        }
    }

    public function test_all_managed_keys_reject_generic_create_update_and_delete_with_one_conflict_code(): void
    {
        $headers = $this->genericConfigManagerHeaders();

        foreach (SystemSettings::managedKeys() as $key) {
            $config = $this->setting($key);
            $originalValue = $config->value;

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
            $this->assertSame($originalValue, $config->value);
        }

        $ordinary = SystemConfig::factory()->create(['key' => 'custom.setting']);
        $this->patchJson(ApiRouting::path('/admin/system-configs/').$ordinary->id, ['value' => 'changed'], $headers)
            ->assertOk()
            ->assertJsonPath('data.system_config.value', 'changed');
        $this->deleteJson(ApiRouting::path('/admin/system-configs/').$ordinary->id, [], $headers)->assertOk();
        $this->assertModelMissing($ordinary);
    }

    public function test_generic_update_cannot_rename_an_ordinary_config_to_a_managed_key(): void
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

    public function test_generic_create_rejects_a_managed_key_even_when_its_row_is_missing(): void
    {
        $headers = $this->genericConfigManagerHeaders();
        $this->setting(SystemSettings::FAVICON_URL_KEY)->deleteQuietly();

        $this->postJson(ApiRouting::path('/admin/system-configs'), [
            'name' => 'Bypass',
            'key' => SystemSettings::FAVICON_URL_KEY,
            'value' => 'https://cdn.example.test/favicon.ico',
            'type' => SystemConfig::TYPE_STRING,
            'config_group' => SystemSettings::BRANDING_GROUP,
        ], $headers)
            ->assertConflict()
            ->assertJsonPath('error_code', ManagedSystemSettingException::ERROR_CODE);

        $this->assertDatabaseMissing('system_configs', ['key' => SystemSettings::FAVICON_URL_KEY]);
    }

    public function test_branding_update_fails_before_writing_when_a_managed_row_is_missing(): void
    {
        $headers = $this->settingsManagerHeaders();
        $this->setting(SystemSettings::FAVICON_URL_KEY)->deleteQuietly();
        $existingKeys = array_values(array_diff(SystemSettings::brandingKeys(), [SystemSettings::FAVICON_URL_KEY]));
        $originalValues = SystemConfig::query()
            ->whereIn('key', $existingKeys)
            ->orderBy('key')
            ->pluck('value', 'key')
            ->all();
        Exceptions::fake([LogicException::class]);

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            'navigation_logo_url' => 'https://cdn.example.test/navigation.svg',
            'login_logo_url' => 'https://cdn.example.test/login.svg',
            'login_background_url' => 'https://cdn.example.test/background.jpg',
            'favicon_url' => 'https://cdn.example.test/favicon.ico',
        ], $headers)->assertStatus(500);

        $this->assertSame(
            $originalValues,
            SystemConfig::query()
                ->whereIn('key', $existingKeys)
                ->orderBy('key')
                ->pluck('value', 'key')
                ->all(),
        );
        $this->assertDatabaseMissing('system_configs', ['key' => SystemSettings::FAVICON_URL_KEY]);
        Exceptions::assertReported(LogicException::class);
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
     * @return array{navigation_logo_url: ?string, login_logo_url: ?string, login_background_url: ?string, favicon_url: ?string}
     */
    private function brandingPayload(): array
    {
        return [
            'navigation_logo_url' => null,
            'login_logo_url' => null,
            'login_background_url' => null,
            'favicon_url' => null,
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
