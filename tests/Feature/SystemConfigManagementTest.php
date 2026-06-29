<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class SystemConfigManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_system_config_crud_supports_querying_typed_values_validation_and_audit(): void
    {
        $this->seedSystemConfigPermissions();

        $user = User::factory()->create(['email' => 'config-admin@example.com']);
        $user->givePermissionTo(['system.config.view', 'system.config.create', 'system.config.update', 'system.config.delete']);
        $token = $this->adminTokenFor($user);

        $create = $this->postJson('/api/admin/system-configs', [
            'name' => '站点名称',
            'key' => 'site.name',
            'value' => 'Admin9',
            'type' => SystemConfig::TYPE_STRING,
            'config_group' => 'site',
            'is_public' => true,
            'sort' => 10,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.key', 'site.name')
            ->assertJsonPath('data.system_config.value', 'Admin9')
            ->assertJsonPath('data.system_config.is_public', true)
            ->assertHeader('X-Request-Id');

        $configId = $create->json('data.system_config.id');
        $this->assertIsInt($configId);

        $this->postJson('/api/admin/system-configs', [
            'name' => '重复站点名称',
            'key' => 'site.name',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->postJson('/api/admin/system-configs', [
            'name' => '生产密钥',
            'key' => 'site.secret',
            'value' => 'should-not-be-configured-here',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $flags = SystemConfig::factory()->create([
            'name' => '功能开关',
            'key' => 'feature.enabled',
            'value' => 'true',
            'type' => SystemConfig::TYPE_BOOLEAN,
            'config_group' => 'feature',
            'is_public' => false,
            'sort' => 30,
        ]);

        $this->getJson('/api/admin/system-configs?'.http_build_query([
            'config_group' => 'site',
            'keyword' => '站点',
            'sorts' => '-sort',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination', 'page')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 15)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['key' => 'site.name'])
            ->assertJsonMissing(['key' => 'feature.enabled']);

        $this->getJson('/api/admin/system-configs/'.$flags->id, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.value', true);

        $this->patchJson('/api/admin/system-configs/'.$configId, [
            'value' => '{invalid-json',
            'type' => SystemConfig::TYPE_JSON,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->patchJson('/api/admin/system-configs/'.$configId, [
            'name' => '站点设置',
            'value' => '{"title":"Admin9"}',
            'type' => SystemConfig::TYPE_JSON,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.name', '站点设置')
            ->assertJsonPath('data.system_config.value.title', 'Admin9');

        /** @var Activity $activity */
        $activity = Activity::query()
            ->where('subject_type', (new SystemConfig)->getMorphClass())
            ->where('subject_id', $configId)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('updated', $activity->event);
        $this->assertSame('admin.system-configs.update', $activity->properties->get('route'));
        $this->assertNotEmpty($activity->properties->get('request_id'));

        $this->deleteJson('/api/admin/system-configs/'.$configId, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'deleted');

        $this->assertModelMissing(new SystemConfig(['id' => $configId]));
    }

    public function test_system_config_update_revalidates_value_when_type_changes(): void
    {
        $this->createPermission('system.config.update');

        $user = User::factory()->create(['email' => 'config-type-change@example.com']);
        $user->givePermissionTo('system.config.update');
        $token = $this->adminTokenFor($user);

        $config = SystemConfig::factory()->create([
            'name' => '配置值',
            'key' => 'feature.count',
            'value' => 'enabled',
            'type' => SystemConfig::TYPE_STRING,
        ]);

        foreach ([SystemConfig::TYPE_INTEGER, SystemConfig::TYPE_BOOLEAN, SystemConfig::TYPE_JSON] as $type) {
            $this->patchJson('/api/admin/system-configs/'.$config->id, [
                'type' => $type,
            ], ['Authorization' => 'Bearer '.$token])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 422);

            $this->assertSame(SystemConfig::TYPE_STRING, $config->refresh()->type);
        }
    }

    public function test_system_config_store_validates_typed_values(): void
    {
        $this->createPermission('system.config.create');

        $user = User::factory()->create(['email' => 'config-store-types@example.com']);
        $user->givePermissionTo('system.config.create');
        $token = $this->adminTokenFor($user);

        foreach ([SystemConfig::TYPE_INTEGER, SystemConfig::TYPE_BOOLEAN, SystemConfig::TYPE_JSON] as $type) {
            $this->postJson('/api/admin/system-configs', [
                'name' => 'Invalid '.$type,
                'key' => 'invalid.'.$type,
                'value' => 'not-a-valid-'.$type,
                'type' => $type,
            ], ['Authorization' => 'Bearer '.$token])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 422);
        }

        $this->postJson('/api/admin/system-configs', [
            'name' => 'Valid integer',
            'key' => 'valid.integer',
            'value' => '123',
            'type' => SystemConfig::TYPE_INTEGER,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.value', 123);

        $this->postJson('/api/admin/system-configs', [
            'name' => 'Valid boolean',
            'key' => 'valid.boolean',
            'value' => 'false',
            'type' => SystemConfig::TYPE_BOOLEAN,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.value', false);

        $this->postJson('/api/admin/system-configs', [
            'name' => 'Valid json',
            'key' => 'valid.json',
            'value' => '{"enabled":true}',
            'type' => SystemConfig::TYPE_JSON,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.value.enabled', true);
    }

    public function test_system_config_rejects_sensitive_keys_and_values(): void
    {
        $this->createPermission('system.config.create');
        $this->createPermission('system.config.update');

        $user = User::factory()->create(['email' => 'config-sensitive@example.com']);
        $user->givePermissionTo(['system.config.create', 'system.config.update']);
        $token = $this->adminTokenFor($user);

        $this->postJson('/api/admin/system-configs', [
            'name' => 'API Key',
            'key' => 'payment.api_key',
            'value' => 'plain-public-value',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->postJson('/api/admin/system-configs', [
            'name' => 'Authorization Header',
            'key' => 'payment.header',
            'value' => 'Authorization: Bearer example',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $config = SystemConfig::factory()->public()->create([
            'name' => '普通公开配置',
            'key' => 'site.public_title',
            'value' => 'Admin9',
            'type' => SystemConfig::TYPE_STRING,
        ]);

        $this->patchJson('/api/admin/system-configs/'.$config->id, [
            'key' => 'site.jwt',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->patchJson('/api/admin/system-configs/'.$config->id, [
            'value' => '{"token":"example"}',
            'type' => SystemConfig::TYPE_JSON,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->patchJson('/api/admin/system-configs/'.$config->id, [
            'name' => '普通公开配置更新',
            'value' => '{"title":"Admin9"}',
            'type' => SystemConfig::TYPE_JSON,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.system_config.name', '普通公开配置更新')
            ->assertJsonPath('data.system_config.value.title', 'Admin9');
    }

    public function test_system_config_filters_use_package_like_resolvers_for_keyword_search(): void
    {
        $this->createPermission('system.config.view');

        $user = User::factory()->create(['email' => 'config-filter@example.com']);
        $user->givePermissionTo('system.config.view');
        $token = $this->adminTokenFor($user);

        SystemConfig::factory()->create([
            'name' => '功能配置',
            'key' => 'feature.percent',
            'description' => 'feature description marker',
        ]);
        SystemConfig::factory()->create([
            'name' => '普通配置',
            'key' => 'feature.ordinary',
            'description' => 'ordinary marker',
        ]);

        $this->getJson('/api/admin/system-configs?'.http_build_query([
            'keyword' => 'feature.percent',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['key' => 'feature.percent'])
            ->assertJsonMissing(['key' => 'feature.ordinary']);
    }

    public function test_system_config_write_operations_reject_users_without_required_permission(): void
    {
        $this->createPermission('system.config.view');
        $this->createPermission('system.config.create');

        $viewer = User::factory()->create(['email' => 'config-viewer@example.com']);
        $viewer->givePermissionTo('system.config.view');
        $viewerToken = $this->adminTokenFor($viewer);

        $this->getJson('/api/admin/system-configs', ['Authorization' => 'Bearer '.$viewerToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/admin/system-configs', [
            'name' => '无权配置',
            'key' => 'site.denied',
        ], ['Authorization' => 'Bearer '.$viewerToken])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertHeader('X-Request-Id');
    }

    private function seedSystemConfigPermissions(): void
    {
        $this->createPermission('system.config.view');
        $this->createPermission('system.config.create');
        $this->createPermission('system.config.update');
        $this->createPermission('system.config.delete');
    }
}
