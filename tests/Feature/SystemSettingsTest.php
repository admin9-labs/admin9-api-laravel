<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\SystemConfig;
use App\Support\ApiRouting;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_branding_settings_store_file_paths_and_return_derived_urls(): void
    {
        config(['filesystems.disks.public.url' => 'https://files.example.test/storage']);
        Storage::fake('public');
        $navigationLogo = $this->readyImage('navigation.png');
        $loginBackground = $this->readyImage('background.jpg');
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.config.update']));
        $payload = [
            'navigation_logo_path' => $navigationLogo->path,
            'login_logo_path' => null,
            'login_background_path' => $loginBackground->path,
            'favicon_path' => $navigationLogo->path,
        ];

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo_path', $navigationLogo->path)
            ->assertJsonPath('data.branding.navigation_logo_url', Storage::disk('public')->url($navigationLogo->path))
            ->assertJsonPath('data.branding.login_logo_path', null)
            ->assertJsonPath('data.branding.login_logo_url', null)
            ->assertJsonPath('data.branding.login_background_path', $loginBackground->path)
            ->assertJsonPath('data.branding.login_background_url', Storage::disk('public')->url($loginBackground->path))
            ->assertJsonPath('data.branding.favicon_path', $navigationLogo->path)
            ->assertJsonPath('data.branding.favicon_url', Storage::disk('public')->url($navigationLogo->path));

        $this->assertSame($navigationLogo->path, $this->configValue(SystemSettings::NAVIGATION_LOGO_KEY));
        $this->assertNull($this->configValue(SystemSettings::LOGIN_LOGO_KEY));
        $this->assertSame($loginBackground->path, $this->configValue(SystemSettings::LOGIN_BACKGROUND_KEY));
        $this->assertSame($navigationLogo->path, $this->configValue(SystemSettings::FAVICON_KEY));

        $this->getJson(ApiRouting::path('/system-settings/public'))
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo_path', $navigationLogo->path)
            ->assertJsonPath('data.branding.navigation_logo_url', Storage::disk('public')->url($navigationLogo->path));

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $this->emptyBrandingPayload(), $headers)
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo_path', null)
            ->assertJsonPath('data.branding.navigation_logo_url', null);

        $this->assertNull($this->configValue(SystemSettings::NAVIGATION_LOGO_KEY));
    }

    public function test_branding_settings_reject_ids_urls_and_unavailable_files(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.config.update']));
        $document = File::factory()->create(['disk' => 'public', 'type' => 'document']);
        $failed = $this->readyImage('failed.png', ['status' => File::STATUS_FAILED]);
        $claimed = $this->readyImage('claimed.png', ['deletion_token' => fake()->uuid()]);
        $private = $this->readyImage('private.png', ['disk' => 'local']);

        foreach ([
            123,
            'https://files.example.test/storage/files/external.png',
            '/storage/files/external.png',
            'files/missing.png',
            $document->path,
            $failed->path,
            $claimed->path,
            $private->path,
        ] as $invalidPath) {
            $payload = $this->emptyBrandingPayload();
            $payload['navigation_logo_path'] = $invalidPath;

            $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $payload, $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('navigation_logo_path');
        }
    }

    public function test_branding_settings_reject_legacy_url_fields(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.config.update']));

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), [
            ...$this->emptyBrandingPayload(),
            'navigation_logo_url' => 'https://legacy.example.test/logo.png',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('navigation_logo_url');
    }

    public function test_deleting_a_referenced_file_preserves_the_path_and_invalidates_the_url(): void
    {
        config(['filesystems.disks.public.url' => 'https://files.example.test/storage']);
        Storage::fake('public');
        $file = $this->readyImage('navigation.png');
        Storage::disk('public')->put($file->path, 'image-bytes');
        $headers = $this->authorizationHeader($this->managerTokenFor([
            'system.config.update',
            'system.file.delete',
        ]));
        $payload = $this->emptyBrandingPayload();
        $payload['navigation_logo_path'] = $file->path;

        $this->putJson(ApiRouting::path('/admin/system-settings/branding'), $payload, $headers)->assertOk();
        $this->deleteJson(ApiRouting::path('/admin/files/').$file->id, [], $headers)->assertOk();

        $this->assertSame($file->path, $this->configValue(SystemSettings::NAVIGATION_LOGO_KEY));
        $this->getJson(ApiRouting::path('/system-settings/public'))
            ->assertOk()
            ->assertJsonPath('data.branding.navigation_logo_path', $file->path)
            ->assertJsonPath('data.branding.navigation_logo_url', null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function readyImage(string $filename, array $attributes = []): File
    {
        return File::factory()->create([
            'name' => $filename,
            'type' => 'image',
            'disk' => 'public',
            'path' => 'files/2026/08/'.fake()->uuid().'.'.pathinfo($filename, PATHINFO_EXTENSION),
            'mime_type' => str_ends_with($filename, '.jpg') ? 'image/jpeg' : 'image/png',
            'extension' => pathinfo($filename, PATHINFO_EXTENSION),
            'status' => File::STATUS_READY,
            'deletion_token' => null,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBrandingPayload(): array
    {
        return [
            'navigation_logo_path' => null,
            'login_logo_path' => null,
            'login_background_path' => null,
            'favicon_path' => null,
        ];
    }

    private function configValue(string $key): ?string
    {
        return SystemConfig::query()->where('key', $key)->value('value');
    }

    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }
}
