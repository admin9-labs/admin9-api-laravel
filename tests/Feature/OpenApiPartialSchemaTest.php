<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreDictionaryItemRequest;
use App\Http\Requests\Admin\StoreFileRequest;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateBasicSystemSettingsRequest;
use App\Http\Requests\Admin\UpdateBrandingSystemSettingsRequest;
use App\Http\Requests\Admin\UpdateDictionaryItemRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Http\Requests\Admin\UpdateMemberStatusRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Http\Resources\Admin\DictionaryItemResource;
use App\Http\Resources\Admin\FileResource;
use App\Http\Resources\Admin\MemberResource;
use App\Http\Resources\Admin\MenuResource;
use App\Http\Resources\Admin\PermissionResource;
use App\Support\AdminApiOpenApiContract;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenApiPartialSchemaTest extends TestCase
{
    #[DataProvider('independentSchemaProvider')]
    public function test_existing_schemas_are_normalized_without_their_siblings(string $schemaClass, string $path, mixed $expected): void
    {
        $document = OpenApi::make('3.1.2');
        $document->components->addSchema($schemaClass, Schema::fromType(
            (new ObjectType)->addProperty('mobile', new StringType),
        ));

        app(AdminApiOpenApiContract::class)->transformDocument($document, true);

        $schema = $document->components->schemas[$schemaClass]->toArray();
        $this->assertSame($expected, data_get($schema, $path));
        $this->assertCount(1, $document->components->schemas);
    }

    public static function independentSchemaProvider(): array
    {
        return [
            'dictionary resource' => [DictionaryItemResource::class, 'properties.meta.anyOf.0.type', 'object'],
            'dictionary store' => [StoreDictionaryItemRequest::class, 'properties.meta.anyOf.2.type', 'null'],
            'dictionary update' => [UpdateDictionaryItemRequest::class, 'properties.meta.anyOf.1.type', 'array'],
            'basic settings' => [UpdateBasicSystemSettingsRequest::class, 'additionalProperties', false],
            'branding settings' => [UpdateBrandingSystemSettingsRequest::class, 'properties.favicon_url.format', 'uri'],
            'menu resource without permissions' => [MenuResource::class, 'properties.parent_id.format', 'int64'],
            'menu store' => [StoreMenuRequest::class, 'properties.permission_ids.items.type', 'integer'],
            'menu update' => [UpdateMenuRequest::class, 'properties.permission_ids.items.type', 'integer'],
            'member resource' => [MemberResource::class, 'properties.email.format', 'email'],
            'member store' => [StoreMemberRequest::class, 'allOf.1.anyOf.0.required', ['email']],
            'member update' => [UpdateMemberRequest::class, 'properties.mobile.maxLength', 32],
            'member status' => [UpdateMemberStatusRequest::class, 'required', ['is_active']],
            'file resource' => [FileResource::class, 'properties.status.enum', ['pending', 'ready', 'failed']],
            'file store' => [StoreFileRequest::class, 'properties.file.contentMediaType', 'application/octet-stream'],
        ];
    }

    public function test_menu_permissions_reference_is_added_without_menu_request_schemas(): void
    {
        $document = OpenApi::make('3.1.2');
        $document->components->addSchema(MenuResource::class, Schema::fromType(new ObjectType));
        $document->components->addSchema(PermissionResource::class, Schema::fromType(new ObjectType));

        app(AdminApiOpenApiContract::class)->transformDocument($document, true);

        $menu = $document->components->schemas[MenuResource::class]->toArray();
        $permissionReference = $document->components->getSchemaReference(PermissionResource::class)->toArray();
        $this->assertSame($permissionReference, $menu['properties']['permissions']['items']);
        $this->assertContains('permissions', $menu['required']);
    }

    public function test_missing_permission_schema_preserves_the_existing_menu_permissions_shape(): void
    {
        $document = OpenApi::make('3.1.2');
        $permissions = (new ArrayType)->setItems(new ObjectType);
        $document->components->addSchema(MenuResource::class, Schema::fromType(
            (new ObjectType)->addProperty('permissions', $permissions),
        ));

        app(AdminApiOpenApiContract::class)->transformDocument($document, true);

        $menu = $document->components->schemas[MenuResource::class]->toArray();
        $this->assertSame($permissions->toArray(), $menu['properties']['permissions']);
        $this->assertArrayHasKey('permission_ids', $menu['properties']);
    }

    public function test_an_empty_document_does_not_gain_unrelated_schemas(): void
    {
        $document = OpenApi::make('3.1.2');

        app(AdminApiOpenApiContract::class)->transformDocument($document, true);

        $this->assertSame([], $document->components->schemas);
    }
}
