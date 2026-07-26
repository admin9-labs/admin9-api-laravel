<?php

namespace App\Support;

use App\Http\Requests\Admin\StoreDictionaryItemRequest;
use App\Http\Requests\Admin\StoreMediaRequest;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateDictionaryItemRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Http\Requests\Admin\UpdateMemberStatusRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Http\Resources\Admin\ActivityLogResource;
use App\Http\Resources\Admin\DictionaryItemResource;
use App\Http\Resources\Admin\LoginLogResource;
use App\Http\Resources\Admin\MediaResource;
use App\Http\Resources\Admin\MemberResource;
use App\Http\Resources\Admin\MenuResource;
use App\Http\Resources\Admin\PermissionResource;
use App\Http\Resources\Admin\SystemConfigResource;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use LogicException;

class AdminApiOpenApiContract
{
    public function transformOperation(Operation $operation, RouteInfo $routeInfo): void
    {
        $routeName = $routeInfo->route->getName();

        if ($routeName === 'admin.activity-logs.index') {
            $this->normalizeLogFilterParameters($operation, ['subject_id', 'causer_id']);
        }

        if ($routeName === 'admin.login-logs.index') {
            $this->normalizeLogFilterParameters($operation, ['subject_id']);
        }

        if ($routeName === 'admin.media.store' && $operation->requestBodyObject !== null) {
            $schema = $operation->requestBodyObject->content['application/json'] ?? null;

            if ($schema !== null) {
                unset($operation->requestBodyObject->content['application/json']);
                $operation->requestBodyObject->setContent('multipart/form-data', $schema);
            }
        }
    }

    public function transformDocument(OpenApi $document): void
    {
        $this->normalizeActivityLogSchema($this->objectSchema($document, ActivityLogResource::class));
        $this->normalizeLoginLogSchema($this->objectSchema($document, LoginLogResource::class));
        $this->normalizeDictionaryMetaSchemas($document);
        $this->normalizeSystemConfigSchemas($document);
        $this->normalizeMenuSchemas($document);
        $this->normalizeMemberSchemas($document);
        $this->normalizeMediaSchemas($document);
    }

    /**
     * @param  array<int, string>  $idParameterNames
     */
    private function normalizeLogFilterParameters(Operation $operation, array $idParameterNames): void
    {
        foreach ($operation->parameters as $parameter) {
            if (! $parameter instanceof Parameter) {
                continue;
            }

            if ($parameter->name === 'created_at') {
                $parameter->setSchema(Schema::fromType(
                    (new ArrayType)
                        ->setMin(2)
                        ->setMax(2)
                        ->setItems((new StringType)->format('date'))
                ));
            }

            if (in_array($parameter->name, $idParameterNames, true)) {
                $parameter->setSchema(Schema::fromType((new IntegerType)->format('int64')));
            }
        }
    }

    private function normalizeActivityLogSchema(ObjectType $schema): void
    {
        $schema
            ->addProperty('id', (new IntegerType)->format('int64'))
            ->addProperty('log_name', (new StringType)->nullable(true))
            ->addProperty('event', (new StringType)->nullable(true))
            ->addProperty('subject_type', (new StringType)->nullable(true))
            ->addProperty('subject_id', (new IntegerType)->format('int64')->nullable(true))
            ->addProperty('causer_type', (new StringType)->nullable(true))
            ->addProperty('causer_id', (new IntegerType)->format('int64')->nullable(true));
    }

    private function normalizeLoginLogSchema(ObjectType $schema): void
    {
        $schema
            ->addProperty('id', (new IntegerType)->format('int64'))
            ->addProperty('subject_id', (new IntegerType)->format('int64')->nullable(true));
    }

    private function normalizeDictionaryMetaSchemas(OpenApi $document): void
    {
        foreach ([
            DictionaryItemResource::class,
            StoreDictionaryItemRequest::class,
            UpdateDictionaryItemRequest::class,
        ] as $schemaClass) {
            $this->objectSchema($document, $schemaClass)->addProperty(
                'meta',
                (new ObjectType)->additionalProperties(new MixedType)->nullable(true),
            );
        }
    }

    private function normalizeSystemConfigSchemas(OpenApi $document): void
    {
        $resolvedValue = (new AnyOf)->setItems([
            new StringType,
            new IntegerType,
            new NumberType,
            new BooleanType,
            (new ObjectType)->additionalProperties(new MixedType),
            (new ArrayType)->setItems(new MixedType),
            new NullType,
        ]);

        $this->objectSchema($document, SystemConfigResource::class)
            ->addProperty('value', $resolvedValue);
    }

    private function normalizeMenuSchemas(OpenApi $document): void
    {
        $menuSchema = $this->objectSchema($document, MenuResource::class);
        $menuSchema
            ->addProperty('id', (new IntegerType)->format('int64'))
            ->addProperty('parent_id', (new IntegerType)->format('int64')->nullable(true))
            ->addProperty('permission_ids', (new ArrayType)->setItems((new IntegerType)->format('int64')))
            ->addProperty('permission_names', (new ArrayType)->setItems(new StringType))
            ->addProperty(
                'permissions',
                (new ArrayType)->setItems($document->components->getSchemaReference(
                    $this->schemaComponentName($document, PermissionResource::class),
                )),
            )
            ->addRequired(['permission_ids', 'permission_names', 'permissions']);

        foreach ([StoreMenuRequest::class, UpdateMenuRequest::class] as $schemaClass) {
            $requestSchema = $this->objectSchema($document, $schemaClass);
            $requestSchema->addProperty(
                'permission_ids',
                (new ArrayType)->setItems((new IntegerType)->format('int64')),
            );
            unset(
                $requestSchema->properties['permission_id'],
                $requestSchema->properties['permission_name'],
            );
        }
    }

    private function normalizeMemberSchemas(OpenApi $document): void
    {
        $memberComponent = $this->schema($document, MemberResource::class);

        if (! $memberComponent->type instanceof ObjectType) {
            throw new LogicException(sprintf('Expected Scramble object schema [%s] was not generated.', MemberResource::class));
        }

        $memberComponent->type = $memberComponent->type->clone();
        $memberComponent->type
            ->addProperty('id', (new IntegerType)->format('int64'))
            ->addProperty('name', new StringType)
            ->addProperty('email', (new StringType)->format('email')->nullable(true))
            ->addProperty('mobile', (new StringType)->nullable(true))
            ->addProperty('is_active', new BooleanType)
            ->addProperty('last_login_at', (new StringType)->nullable(true))
            ->addProperty('last_login_ip', (new StringType)->nullable(true))
            ->addProperty('created_at', new StringType)
            ->addProperty('updated_at', new StringType)
            ->addRequired([
                'id',
                'name',
                'email',
                'mobile',
                'is_active',
                'last_login_at',
                'last_login_ip',
                'created_at',
                'updated_at',
            ]);

        $storeMemberSchema = $this->schema($document, StoreMemberRequest::class);
        $this->objectSchema($document, StoreMemberRequest::class)
            ->addProperty('name', (new StringType)->setMin(1)->setMax(255));
        $storeMemberSchema->type = (new AllOf)->setItems([
            $storeMemberSchema->type,
            (new AnyOf)->setItems([
                (new ObjectType)
                    ->addProperty('email', (new StringType)->format('email')->setMin(1)->pattern('.*\\S.*'))
                    ->addRequired(['email']),
                (new ObjectType)
                    ->addProperty('mobile', (new StringType)->setMin(1)->pattern('.*\\S.*'))
                    ->addRequired(['mobile']),
            ]),
        ]);

        $this->objectSchema($document, UpdateMemberRequest::class)
            ->addProperty('name', (new StringType)->setMin(1)->setMax(255))
            ->addProperty('email', (new StringType)->format('email')->setMax(255)->nullable(true))
            ->addProperty('mobile', (new StringType)->setMax(32)->nullable(true));

        $this->objectSchema($document, UpdateMemberStatusRequest::class)
            ->addProperty('is_active', new BooleanType)
            ->addRequired(['is_active']);
    }

    private function normalizeMediaSchemas(OpenApi $document): void
    {
        $this->objectSchema($document, MediaResource::class)
            ->addProperty('id', (new IntegerType)->format('int64'))
            ->addProperty('name', new StringType)
            ->addProperty('url', (new StringType)->format('uri')->nullable(true))
            ->addProperty('mime_type', new StringType)
            ->addProperty('extension', new StringType)
            ->addProperty('size', (new IntegerType)->format('int64'))
            ->addProperty('width', (new IntegerType)->nullable(true))
            ->addProperty('height', (new IntegerType)->nullable(true))
            ->addProperty('status', (new StringType)->enum([
                'pending',
                'ready',
                'failed',
            ]))
            ->addProperty('created_at', new StringType)
            ->addRequired([
                'id',
                'name',
                'url',
                'mime_type',
                'extension',
                'size',
                'width',
                'height',
                'status',
                'created_at',
            ]);

        $this->objectSchema($document, StoreMediaRequest::class)
            ->addProperty(
                'file',
                (new StringType)
                    ->format('binary')
                    ->contentMediaType('application/octet-stream')
                    ->setDescription('JPEG, PNG, WebP, or GIF image up to 5 MiB. The filename extension must match the detected MIME type.'),
            )
            ->addRequired(['file']);
    }

    /**
     * @param  class-string  $schemaClass
     */
    private function objectSchema(OpenApi $document, string $schemaClass): ObjectType
    {
        $schema = $document->components->schemas[$this->schemaComponentName($document, $schemaClass)] ?? null;

        if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
            throw new LogicException(sprintf('Expected Scramble object schema [%s] was not generated.', $schemaClass));
        }

        return $schema->type;
    }

    /**
     * @param  class-string  $schemaClass
     */
    private function schema(OpenApi $document, string $schemaClass): Schema
    {
        $schema = $document->components->schemas[$this->schemaComponentName($document, $schemaClass)] ?? null;

        if (! $schema instanceof Schema) {
            throw new LogicException(sprintf('Expected Scramble schema [%s] was not generated.', $schemaClass));
        }

        return $schema;
    }

    /**
     * @param  class-string  $schemaClass
     */
    private function schemaComponentName(OpenApi $document, string $schemaClass): string
    {
        if (array_key_exists($schemaClass, $document->components->schemas)) {
            return $schemaClass;
        }

        $dotNotationClass = str_replace('\\', '.', $schemaClass);

        if (array_key_exists($dotNotationClass, $document->components->schemas)) {
            return $dotNotationClass;
        }

        $matchingComponentNames = collect(array_keys($document->components->schemas))
            ->filter(fn (string $componentName): bool => str_contains(
                class_basename($componentName),
                class_basename($schemaClass),
            ))
            ->values();

        if ($matchingComponentNames->count() !== 1) {
            throw new LogicException(sprintf('Expected one Scramble schema component for [%s].', $schemaClass));
        }

        return $matchingComponentNames->first();
    }
}
