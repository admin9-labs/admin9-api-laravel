<?php

namespace App\Support;

use App\Http\Requests\Admin\StoreDictionaryItemRequest;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateDictionaryItemRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Http\Resources\Admin\ActivityLogResource;
use App\Http\Resources\Admin\DictionaryItemResource;
use App\Http\Resources\Admin\LoginLogResource;
use App\Http\Resources\Admin\MenuResource;
use App\Http\Resources\Admin\PermissionResource;
use App\Http\Resources\Admin\SystemConfigResource;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
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
use Illuminate\Support\Facades\Route;
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
    }

    public function transformDocument(OpenApi $document): void
    {
        $this->addForbiddenResponses($document);
        $this->normalizeActivityLogSchema($this->objectSchema($document, ActivityLogResource::class));
        $this->normalizeLoginLogSchema($this->objectSchema($document, LoginLogResource::class));
        $this->normalizeDictionaryMetaSchemas($document);
        $this->normalizeSystemConfigSchemas($document);
        $this->normalizeMenuSchemas($document);
    }

    private function addForbiddenResponses(OpenApi $document): void
    {
        $forbiddenOperationIds = collect(Route::getRoutes())
            ->filter(fn ($route): bool => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'permission:')))
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->merge(['member.auth.refresh', 'admin.auth.refresh'])
            ->unique()
            ->all();
        $responseReference = new Reference('responses', 'ForbiddenResponse', $document->components);

        $document->components->add($responseReference, $this->forbiddenResponse());

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                if (in_array($operation->operationId, $forbiddenOperationIds, true)) {
                    $operation->addResponse($responseReference);
                }
            }
        }
    }

    private function forbiddenResponse(): OpenApiResponse
    {
        $emptyArray = static fn (): ArrayType => (new ArrayType)
            ->setItems(new MixedType)
            ->setMax(0);

        $envelope = (new ObjectType)
            ->addProperty('success', (new BooleanType)->example(false))
            ->addProperty('code', (new IntegerType)->example(403))
            ->addProperty('message', (new StringType)->example('Forbidden'))
            ->addProperty('data', $emptyArray())
            ->addProperty('errors', $emptyArray())
            ->addProperty('request_id', new StringType)
            ->setRequired(['success', 'code', 'message', 'data', 'errors', 'request_id']);

        return OpenApiResponse::make(403)
            ->setDescription('Forbidden')
            ->setContent('application/json', Schema::fromType($envelope));
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
    private function schemaComponentName(OpenApi $document, string $schemaClass): string
    {
        if (array_key_exists($schemaClass, $document->components->schemas)) {
            return $schemaClass;
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
