<?php

namespace Tests\Feature;

use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_branding_settings_accept_valid_urls_and_clear_values(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.config.update']));
        $payload = [
            'navigation_logo_url' => 'https://cdn.example.test/logo.svg',
            'login_logo_url' => 'https://cdn.example.test/login.svg',
            'login_background_url' => null,
            'favicon_url' => 'https://cdn.example.test/favicon.ico',
        ];

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
        ], $headers)->assertOk();
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

    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }
}
