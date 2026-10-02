<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\DictionaryItemController;
use App\Http\Controllers\Api\Admin\DictionaryTypeController;
use App\Http\Requests\Admin\StoreDictionaryItemRequest;
use App\Http\Requests\Admin\StoreDictionaryTypeRequest;
use App\Http\Requests\Admin\UpdateDictionaryItemRequest;
use App\Http\Requests\Admin\UpdateDictionaryTypeRequest;
use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\FailingActivity;
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

        $createType = $this->postJson(ApiRouting::path('/admin/dictionary-types'), [
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

        $this->postJson(ApiRouting::path('/admin/dictionary-types'), [
            'name' => '重复字典',
            'code' => 'status',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertHeader('X-Request-Id');

        $enabled = $this->postJson(ApiRouting::path('/admin/dictionary-items'), [
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

        $this->postJson(ApiRouting::path('/admin/dictionary-items'), [
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

        $this->getJson(ApiRouting::path('/admin/dictionary-items?').http_build_query([
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

        $this->patchJson(ApiRouting::path('/admin/dictionary-items/').$itemId, [
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

        $this->deleteJson(ApiRouting::path('/admin/dictionary-items/').$itemId, [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'deleted');

        $this->deleteJson(ApiRouting::path('/admin/dictionary-types/').$typeId, [], ['Authorization' => 'Bearer '.$token])
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

        $this->patchJson(ApiRouting::path('/admin/dictionary-items/').$movingItem->id, [
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

        $this->deleteJson(ApiRouting::path('/admin/dictionary-types/').$type->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonValidationErrors('dictionary_type');

        $this->assertModelExists($type);
        $this->assertModelExists($item);

        $emptyType = DictionaryType::factory()->create(['code' => 'empty_guarded_type']);

        $this->deleteJson(ApiRouting::path('/admin/dictionary-types/').$emptyType->id, [], ['Authorization' => 'Bearer '.$token])
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

        $this->getJson(ApiRouting::path('/admin/dictionary-types?').http_build_query([
            'keyword' => 'description marker',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['code' => 'status_filter'])
            ->assertJsonMissing(['code' => 'ordinary_status']);

        $this->getJson(ApiRouting::path('/admin/dictionary-items?').http_build_query([
            'keyword' => 'item-value-marker',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['code' => 'enabled_filter'])
            ->assertJsonMissing(['code' => 'ordinary_item']);
    }

    public function test_dictionary_type_deletion_rechecks_items_inside_its_transaction(): void
    {
        $token = $this->managerTokenFor(['system.dictionary.delete']);
        $type = DictionaryType::factory()->create();
        $inserted = false;

        Event::listen(TransactionBeginning::class, function () use ($type, &$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DictionaryItem::factory()->create(['dictionary_type_id' => $type->id]);
        });

        $this->deleteJson(ApiRouting::path('/admin/dictionary-types/').$type->id, [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();

        $this->assertTrue($inserted);
        $this->assertModelExists($type);
    }

    public function test_dictionary_item_rejects_nested_type_ids_without_server_errors(): void
    {
        $token = $this->managerTokenFor(['system.dictionary.create', 'system.dictionary.update']);
        $headers = ['Authorization' => 'Bearer '.$token];
        $item = DictionaryItem::factory()->create();
        $payload = [
            'dictionary_type_id' => [['id' => $item->dictionary_type_id]],
            'name' => 'Invalid dictionary type',
            'code' => 'invalid_type',
        ];

        $this->postJson(ApiRouting::path('/admin/dictionary-items'), $payload, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('dictionary_type_id');
        $this->patchJson(ApiRouting::path('/admin/dictionary-items/').$item->id, $payload, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('dictionary_type_id');
    }

    public function test_dictionary_code_conflicts_after_request_validation_return_field_errors(): void
    {
        foreach ([false, true] as $updating) {
            foreach ([false, true] as $isItem) {
                $parent = DictionaryType::factory()->create();
                $model = $updating
                    ? ($isItem ? DictionaryItem::factory()->create() : DictionaryType::factory()->create())
                    : null;
                $code = 'concurrent_'.($updating ? 'update' : 'create').'_'.($isItem ? 'item' : 'type');
                $payload = ['name' => 'Concurrent dictionary', 'code' => $code];

                if ($isItem) {
                    $payload['dictionary_type_id'] = $parent->id;
                }

                $requestClass = $isItem
                    ? ($updating ? UpdateDictionaryItemRequest::class : StoreDictionaryItemRequest::class)
                    : ($updating ? UpdateDictionaryTypeRequest::class : StoreDictionaryTypeRequest::class);
                $request = $this->validatedDictionaryRequest($requestClass, $payload, $model);
                $isItem
                    ? DictionaryItem::factory()->create(['dictionary_type_id' => $parent->id, 'code' => $code])
                    : DictionaryType::factory()->create(['code' => $code]);

                try {
                    $controller = $this->app->make($isItem ? DictionaryItemController::class : DictionaryTypeController::class);
                    $updating ? $controller->update($request, $model) : $controller->store($request);
                    $this->fail('A concurrent dictionary code collision must produce a field validation error.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('code', $exception->errors());
                }
            }
        }
    }

    public function test_dictionary_item_writes_reject_a_parent_deleted_after_request_validation(): void
    {
        foreach ([false, true] as $updating) {
            $parent = DictionaryType::factory()->create();
            $item = $updating ? DictionaryItem::factory()->create() : null;
            $request = $this->validatedDictionaryRequest(
                $updating ? UpdateDictionaryItemRequest::class : StoreDictionaryItemRequest::class,
                ['dictionary_type_id' => $parent->id, 'name' => 'New item', 'code' => 'new_item'],
                $item,
            );
            $parent->delete();

            try {
                $controller = $this->app->make(DictionaryItemController::class);
                $updating ? $controller->update($request, $item) : $controller->store($request);
                $this->fail('A deleted parent must be rejected before the dictionary item is written.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('dictionary_type_id', $exception->errors());
            }

            $this->assertDatabaseMissing('dictionary_items', ['dictionary_type_id' => $parent->id]);
        }
    }

    public function test_dictionary_sorts_must_fit_their_database_columns(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->managerTokenFor(['system.dictionary.create', 'system.dictionary.update'])];
        $type = DictionaryType::factory()->create();
        $item = DictionaryItem::factory()->create(['dictionary_type_id' => $type->id]);

        foreach (['dictionary-types' => $type, 'dictionary-items' => $item] as $resource => $model) {
            $this->postJson(ApiRouting::path('/admin/'.$resource), [
                'name' => 'Invalid sort', 'code' => 'invalid_sort', 'sort' => 4294967296, 'dictionary_type_id' => $type->id,
            ], $headers)->assertUnprocessable()->assertJsonValidationErrors('sort');
            $this->patchJson(ApiRouting::path('/admin/'.$resource.'/').$model->id, [
                'sort' => 4294967296,
            ], $headers)->assertUnprocessable()->assertJsonValidationErrors('sort');
        }
    }

    public function test_dictionary_mutations_roll_back_when_the_audit_write_fails(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->managerTokenFor([
            'system.dictionary.create', 'system.dictionary.update', 'system.dictionary.delete',
        ])];
        $type = DictionaryType::factory()->create();
        $item = DictionaryItem::factory()->create();
        $activityCount = Activity::query()->count();
        config(['activitylog.activity_model' => FailingActivity::class]);
        Exceptions::fake([RuntimeException::class]);

        foreach (['dictionary-types' => $type, 'dictionary-items' => $item] as $resource => $model) {
            $originalName = $model->name;
            $this->postJson(ApiRouting::path('/admin/'.$resource), [
                'name' => 'Rollback create', 'code' => 'rollback_create', 'dictionary_type_id' => $type->id,
            ], $headers)->assertStatus(500);
            $this->assertDatabaseMissing($model->getTable(), ['code' => 'rollback_create']);

            $this->patchJson(ApiRouting::path('/admin/'.$resource.'/').$model->id, [
                'name' => 'Rollback update',
            ], $headers)->assertStatus(500);
            $this->assertSame($originalName, $model->fresh()->name);

            $this->deleteJson(ApiRouting::path('/admin/'.$resource.'/').$model->id, [], $headers)->assertStatus(500);
            $this->assertModelExists($model);
        }

        $this->assertSame($activityCount, Activity::query()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_dictionary_write_operations_reject_users_without_required_permission(): void
    {
        $this->createPermission('system.dictionary.view');
        $this->createPermission('system.dictionary.create');

        $viewer = User::factory()->create(['email' => 'dictionary-viewer@example.com']);
        $viewer->givePermissionTo('system.dictionary.view');
        $viewerToken = $this->adminTokenFor($viewer);

        $this->getJson(ApiRouting::path('/admin/dictionary-types'), ['Authorization' => 'Bearer '.$viewerToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson(ApiRouting::path('/admin/dictionary-types'), [
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

    /**
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $payload
     */
    private function validatedDictionaryRequest(string $requestClass, array $payload, ?Model $model = null): FormRequest
    {
        $method = $model === null ? 'POST' : 'PATCH';
        $parameter = $model instanceof DictionaryType ? 'dictionary_type' : 'dictionary_item';
        $request = $requestClass::create('/dictionary/'.($model?->id ?? ''), $method, $payload);
        $route = (new Route($method, '/dictionary/{'.$parameter.'?}', fn () => null))->bind($request);
        $route->setParameter($parameter, $model);
        $request->setRouteResolver(fn (): Route => $route);
        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $request->validateResolved();

        return $request;
    }
}
