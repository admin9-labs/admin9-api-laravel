<?php

namespace Tests\Feature;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class DictionaryManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_dictionary_crud_uses_permissions_validation_query_builder_and_resources(): void
    {
        $this->seedDictionaryPermissions();

        $user = User::factory()->create(['email' => 'dictionary-admin@example.com']);
        $user->givePermissionTo(['system.dictionary.view', 'system.dictionary.create', 'system.dictionary.update', 'system.dictionary.delete']);
        $token = $this->adminTokenFor($user);

        $createType = $this->postJson('/api/admin/dictionary-types', [
            'name' => '状态字典',
            'code' => 'status',
            'description' => '通用状态',
            'sort' => 10,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_type.code', 'status')
            ->assertJsonPath('data.dictionary_type.is_active', true)
            ->assertHeader('X-Request-Id');

        $typeId = $createType->json('data.dictionary_type.id');
        $this->assertIsInt($typeId);

        $this->postJson('/api/admin/dictionary-types', [
            'name' => '重复字典',
            'code' => 'status',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertHeader('X-Request-Id');

        $enabled = $this->postJson('/api/admin/dictionary-items', [
            'dictionary_type_id' => $typeId,
            'name' => '启用',
            'code' => 'enabled',
            'value' => '1',
            'meta' => ['color' => 'green'],
            'sort' => 20,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_item.code', 'enabled')
            ->assertJsonPath('data.dictionary_item.type.code', 'status');

        $itemId = $enabled->json('data.dictionary_item.id');
        $this->assertIsInt($itemId);

        $this->postJson('/api/admin/dictionary-items', [
            'dictionary_type_id' => $typeId,
            'name' => '重复启用',
            'code' => 'enabled',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $otherType = DictionaryType::factory()->create(['code' => 'audit_status']);
        DictionaryItem::factory()->create([
            'dictionary_type_id' => $otherType->id,
            'name' => '启用',
            'code' => 'enabled',
            'value' => '1',
        ]);

        $this->getJson('/api/admin/dictionary-items?'.http_build_query([
            'type_code' => 'status',
            'keyword' => '启用',
            'sorts' => '-sort',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination', 'page')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 15)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['code' => 'enabled'])
            ->assertJsonMissing(['code' => 'audit_status']);

        $this->patchJson('/api/admin/dictionary-items/'.$itemId, [
            'name' => '已启用',
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dictionary_item.name', '已启用')
            ->assertJsonPath('data.dictionary_item.is_active', false);

        /** @var Activity $activity */
        $activity = Activity::query()
            ->where('subject_type', (new DictionaryItem)->getMorphClass())
            ->where('subject_id', $itemId)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('updated', $activity->event);
        $this->assertSame('admin.dictionary-items.update', $activity->properties->get('route'));
        $this->assertNotEmpty($activity->properties->get('request_id'));

        $this->deleteJson('/api/admin/dictionary-items/'.$itemId, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'deleted');

        $this->deleteJson('/api/admin/dictionary-types/'.$typeId, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing(new DictionaryType(['id' => $typeId]));
    }

    public function test_dictionary_item_update_rejects_code_collision_when_moving_types(): void
    {
        $this->createPermission('system.dictionary.update');

        $user = User::factory()->create(['email' => 'dictionary-move@example.com']);
        $user->givePermissionTo('system.dictionary.update');
        $token = $this->adminTokenFor($user);

        $sourceType = DictionaryType::factory()->create(['code' => 'source_status']);
        $targetType = DictionaryType::factory()->create(['code' => 'target_status']);
        $movingItem = DictionaryItem::factory()->create([
            'dictionary_type_id' => $sourceType->id,
            'code' => 'enabled',
        ]);
        DictionaryItem::factory()->create([
            'dictionary_type_id' => $targetType->id,
            'code' => 'enabled',
        ]);

        $this->patchJson('/api/admin/dictionary-items/'.$movingItem->id, [
            'dictionary_type_id' => $targetType->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertSame($sourceType->id, $movingItem->refresh()->dictionary_type_id);
    }

    public function test_dictionary_type_with_items_cannot_be_deleted(): void
    {
        $this->createPermission('system.dictionary.delete');

        $user = User::factory()->create(['email' => 'dictionary-delete-guard@example.com']);
        $user->givePermissionTo('system.dictionary.delete');
        $token = $this->adminTokenFor($user);

        $type = DictionaryType::factory()->create(['code' => 'guarded_type']);
        $item = DictionaryItem::factory()->create(['dictionary_type_id' => $type->id]);

        $this->deleteJson('/api/admin/dictionary-types/'.$type->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertModelExists($type);
        $this->assertModelExists($item);

        $emptyType = DictionaryType::factory()->create(['code' => 'empty_guarded_type']);

        $this->deleteJson('/api/admin/dictionary-types/'.$emptyType->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing($emptyType);
    }

    public function test_dictionary_filters_use_package_like_resolvers_for_keyword_search(): void
    {
        $this->createPermission('system.dictionary.view');

        $user = User::factory()->create(['email' => 'dictionary-filter@example.com']);
        $user->givePermissionTo('system.dictionary.view');
        $token = $this->adminTokenFor($user);

        $type = DictionaryType::factory()->create([
            'name' => '状态字典',
            'code' => 'status_filter',
            'description' => 'dictionary description marker',
        ]);
        DictionaryType::factory()->create([
            'name' => '普通字典',
            'code' => 'ordinary_status',
            'description' => 'ordinary marker',
        ]);

        DictionaryItem::factory()->create([
            'dictionary_type_id' => $type->id,
            'name' => '启用选项',
            'code' => 'enabled_filter',
            'value' => 'item-value-marker',
            'description' => 'enabled marker',
        ]);
        DictionaryItem::factory()->create([
            'dictionary_type_id' => $type->id,
            'name' => '普通选项',
            'code' => 'ordinary_item',
            'value' => 'ordinary-value',
            'description' => 'ordinary value marker',
        ]);

        $this->getJson('/api/admin/dictionary-types?'.http_build_query([
            'keyword' => 'description marker',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['code' => 'status_filter'])
            ->assertJsonMissing(['code' => 'ordinary_status']);

        $this->getJson('/api/admin/dictionary-items?'.http_build_query([
            'keyword' => 'item-value-marker',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['code' => 'enabled_filter'])
            ->assertJsonMissing(['code' => 'ordinary_item']);
    }

    public function test_dictionary_write_operations_reject_users_without_required_permission(): void
    {
        $this->createPermission('system.dictionary.view');
        $this->createPermission('system.dictionary.create');

        $viewer = User::factory()->create(['email' => 'dictionary-viewer@example.com']);
        $viewer->givePermissionTo('system.dictionary.view');
        $viewerToken = $this->adminTokenFor($viewer);

        $this->getJson('/api/admin/dictionary-types', ['Authorization' => 'Bearer '.$viewerToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/admin/dictionary-types', [
            'name' => '无权字典',
            'code' => 'denied',
        ], ['Authorization' => 'Bearer '.$viewerToken])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertHeader('X-Request-Id');
    }

    private function seedDictionaryPermissions(): void
    {
        $this->createPermission('system.dictionary.view');
        $this->createPermission('system.dictionary.create');
        $this->createPermission('system.dictionary.update');
        $this->createPermission('system.dictionary.delete');
    }
}
