<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use App\Support\ApiRouting;
use App\Support\Audit\AdminActivityRecorder;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AuditedUrlCredentialsTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_menu_url_credentials_are_removed_from_audits_without_changing_the_menu(): void
    {
        $token = $this->managerTokenFor(['system.menu.create', 'system.activity-log.view']);
        $headers = ['Authorization' => 'Bearer '.$token];
        $url = 'https://user:password@example.test/page?id=1&token=credential&id=2#preview';
        $response = $this->postJson(ApiRouting::path('/admin/menus'), [
            'name' => 'External page', 'code' => 'external-page', 'path' => $url, 'type' => Menu::TYPE_DIRECTORY,
        ], $headers)->assertOk()->assertJsonPath('data.menu.path', $url);

        $menu = Menu::query()->findOrFail($response->json('data.menu.id'));
        $this->assertSame($url, $menu->path);
        $activity = Activity::query()->where('subject_type', $menu->getMorphClass())
            ->where('subject_id', $menu->id)->where('event', 'created')->sole();
        $safeUrl = 'https://example.test/page?id=1&id=2#preview';
        $this->assertSame($safeUrl, $activity->properties['attributes']['path']);

        $this->getJson(ApiRouting::path('/admin/activity-logs?').http_build_query([
            'subject_type' => $menu->getMorphClass(), 'subject_id' => $menu->id, 'event' => 'created',
        ]), $headers)->assertOk()->assertJsonPath('data.0.properties.attributes.path', $safeUrl);
    }

    public function test_existing_audit_url_credentials_are_redacted_when_read(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->managerTokenFor(['system.activity-log.view'])];
        $legacy = Activity::query()->create([
            'log_name' => 'admin', 'event' => 'legacy', 'description' => 'Legacy audit',
            'properties' => ['url' => 'https://example.test/file?token=credential&id=1'],
        ]);

        $this->getJson(ApiRouting::path('/admin/activity-logs?event=legacy'), $headers)
            ->assertOk()->assertJsonPath('data.0.properties.url', 'https://example.test/file?id=1');
        $this->assertSame('https://example.test/file?token=credential&id=1', $legacy->refresh()->properties['url']);
    }

    public function test_explicit_audit_recorders_also_remove_url_credentials(): void
    {
        $actor = User::factory()->create();
        $activity = app(AdminActivityRecorder::class)->record($actor, 'url_updated', [
            'url' => 'https://example.test/file?token=credential&id=1',
        ]);
        $this->assertSame('https://example.test/file?id=1', $activity->properties['url']);

        $this->withHeader('User-Agent', 'https://example.test/client?token=credential');
        $this->postJson(ApiRouting::path('/admin/auth/login'), [
            'email' => $actor->email, 'password' => 'password',
        ])->assertOk();
        $security = app(SecurityActivityRecorder::class)->record($actor, $actor, 'admin', 'credential_changed');
        $this->assertSame('https://example.test/client', $security->properties['user_agent']);
    }
}
