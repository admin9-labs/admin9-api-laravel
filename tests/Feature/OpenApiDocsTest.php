<?php

namespace Tests\Feature;

use App\Support\ApiRouting;
use App\Support\FileUploadPolicy;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    public function test_generated_openapi_document_contains_core_api_contracts(): void
    {
        $document = $this->openApiDocument();

        $this->assertSame('3.1.0', $document['openapi'] ?? null);
        $this->assertSame('Admin9 API Laravel', $document['info']['title'] ?? null);
        $this->assertIsArray($document['paths'] ?? null);
        $this->assertIsArray($document['components'] ?? null);

        foreach ([
            ApiRouting::path('/admin/auth/login') => 'post',
            ApiRouting::path('/admin/auth/me') => 'get',
            ApiRouting::path('/admin/auth/password') => 'put',
            ApiRouting::path('/admin/menus/tree') => 'get',
            ApiRouting::path('/admin/users') => 'get',
            ApiRouting::path('/admin/members') => 'get',
            ApiRouting::path('/admin/users/{user}/password') => 'put',
            ApiRouting::path('/admin/roles') => 'get',
            ApiRouting::path('/admin/permissions') => 'get',
            ApiRouting::path('/admin/dictionary-types') => 'get',
            ApiRouting::path('/admin/system-configs') => 'get',
            ApiRouting::path('/admin/activity-logs') => 'get',
            ApiRouting::path('/admin/login-logs') => 'get',
            ApiRouting::path('/auth/password') => 'put',
        ] as $path => $method) {
            $this->assertArrayHasKey($path, $document['paths']);
            $this->assertArrayHasKey($method, $document['paths'][$path]);
        }
    }

    public function test_generated_openapi_document_uses_the_application_api_prefix_and_only_api_middleware_routes(): void
    {
        $document = $this->openApiDocument();

        $this->assertSame([['url' => 'http://localhost']], $document['servers']);
        $this->assertSame('api', ApiRouting::prefix());

        foreach (array_keys($document['paths']) as $path) {
            $this->assertStringStartsWith('/', $path);
            $this->assertStringStartsWith(ApiRouting::path('/').'/', $path);
        }

        foreach (['/', '/up', '/docs/api', '/docs/api.json'] as $nonApiPath) {
            $this->assertArrayNotHasKey($nonApiPath, $document['paths']);
        }
    }

    public function test_generated_openapi_document_uses_business_response_envelope_and_filters(): void
    {
        $document = $this->openApiDocument();
        $loginResponseSchema = $document['paths'][ApiRouting::path('/admin/auth/login')]['post']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(['success', 'code', 'message', 'data', 'request_id'], $loginResponseSchema['required']);
        $this->assertArrayHasKey('success', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('code', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('message', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('data', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('request_id', $loginResponseSchema['properties']);

        $systemConfigParameters = collect($document['paths'][ApiRouting::path('/admin/system-configs')]['get']['parameters'])
            ->pluck('name')
            ->all();

        foreach (['key', 'name', 'config_group', 'type', 'is_public', 'is_active', 'keyword', 'sort', 'page_size', 'page'] as $parameter) {
            $this->assertContains($parameter, $systemConfigParameters);
        }

        $activityLogParameters = collect($document['paths'][ApiRouting::path('/admin/activity-logs')]['get']['parameters'])
            ->pluck('name')
            ->all();
        $loginLogParameters = collect($document['paths'][ApiRouting::path('/admin/login-logs')]['get']['parameters'])
            ->pluck('name')
            ->all();

        foreach (['log_name', 'event', 'subject_type', 'subject_id', 'causer_id', 'created_at', 'sort', 'page_size', 'page'] as $parameter) {
            $this->assertContains($parameter, $activityLogParameters);
        }

        foreach (['guard', 'event', 'successful', 'account', 'subject_id', 'ip_address', 'created_at', 'sort', 'page_size', 'page'] as $parameter) {
            $this->assertContains($parameter, $loginLogParameters);
        }

        $this->assertSame('bearer', $document['components']['securitySchemes']['http']['scheme'] ?? null);
    }

    public function test_generated_openapi_document_uses_precise_auth_token_schema(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            ApiRouting::path('/auth/login'),
            ApiRouting::path('/auth/refresh'),
            ApiRouting::path('/admin/auth/login'),
            ApiRouting::path('/admin/auth/refresh'),
        ] as $path) {
            $dataProperties = $document['paths'][$path]['post']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'];

            $this->assertSame(['type' => 'string'], $dataProperties['access_token'], "{$path} access_token must be documented as string.");
            $this->assertSame(['type' => 'integer'], $dataProperties['expires_in'], "{$path} expires_in must be documented as integer seconds.");
            $this->assertFalse($this->schemaContainsType($dataProperties['access_token'], 'boolean'), "{$path} access_token must not include boolean.");
        }
    }

    public function test_generated_openapi_document_requires_bearer_security_for_refresh_authentication_failures(): void
    {
        $document = $this->openApiDocument();

        foreach ([ApiRouting::path('/auth/refresh'), ApiRouting::path('/admin/auth/refresh')] as $path) {
            $operation = $document['paths'][$path]['post'];

            $this->assertSame([['http' => []]], $operation['security']);
            $this->assertSame(
                '#/components/responses/ApiUnauthorizedResponse',
                $operation['responses']['401']['$ref'] ?? null,
                "{$path} must document invalid refresh tokens as authentication failures.",
            );
        }
    }

    public function test_permission_middleware_and_disabled_account_refresh_failures_document_forbidden_response(): void
    {
        $document = $this->openApiDocument();
        $operations = $this->operationsById($document);
        $forbiddenOperationIds = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'permission:')
                    || str_starts_with($middleware, 'account.active:')))
            ->map(fn (Route $route): ?string => $route->getName())
            ->filter()
            ->merge(['member.auth.refresh', 'admin.auth.refresh'])
            ->unique()
            ->values();

        foreach ($forbiddenOperationIds as $operationId) {
            $this->assertSame(
                '#/components/responses/ApiForbiddenResponse',
                $operations[$operationId]['responses']['403']['$ref'] ?? null,
                "{$operationId} must document HTTP 403.",
            );
        }

        $forbiddenResponse = $document['components']['responses']['ApiForbiddenResponse'];
        $forbiddenSchema = $forbiddenResponse['content']['application/json']['schema'];
        $this->assertSame(['success', 'code', 'message', 'data', 'errors', 'request_id'], $forbiddenSchema['required']);
        $this->assertSame([false], $forbiddenSchema['properties']['success']['enum']);
        $this->assertSame(403, $forbiddenSchema['properties']['code']['const']);
        $this->assertSame(['account_inactive'], $forbiddenSchema['properties']['error_code']['enum']);
        $this->assertSame('string', $forbiddenResponse['headers']['X-Request-Id']['schema']['type']);

        foreach (['data', 'errors'] as $property) {
            $this->assertStrictEmptyObjectSchema($forbiddenSchema['properties'][$property]);
        }
    }

    public function test_throttled_operations_document_rate_limit_error_contract(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            [ApiRouting::path('/auth/login'), 'post'],
            [ApiRouting::path('/auth/refresh'), 'post'],
            [ApiRouting::path('/auth/me'), 'get'],
            [ApiRouting::path('/auth/password'), 'put'],
            [ApiRouting::path('/auth/logout'), 'post'],
            [ApiRouting::path('/admin/auth/login'), 'post'],
        ] as [$path, $method]) {
            $this->assertSame(
                '#/components/responses/ApiRateLimitResponse',
                $document['paths'][$path][$method]['responses']['429']['$ref'] ?? null,
                "{$method} {$path} must document HTTP 429.",
            );
        }

        $response = $document['components']['responses']['ApiRateLimitResponse'];
        $schema = $response['content']['application/json']['schema'];
        $this->assertSame(['success', 'code', 'message', 'data', 'errors', 'request_id'], $schema['required']);
        $this->assertSame([false], $schema['properties']['success']['enum']);
        $this->assertSame(429, $schema['properties']['code']['const']);
        $this->assertStrictEmptyObjectSchema($schema['properties']['data']);
        $this->assertStrictEmptyObjectSchema($schema['properties']['errors']);

        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $header) {
            $this->assertSame('integer', $response['headers'][$header]['schema']['type'] ?? null);
        }
    }

    public function test_generated_openapi_document_centralizes_error_envelopes_and_headers(): void
    {
        $document = $this->openApiDocument();
        $expectedComponents = [
            401 => 'ApiUnauthorizedResponse',
            403 => 'ApiForbiddenResponse',
            404 => 'ApiNotFoundResponse',
            413 => 'ApiContentTooLargeResponse',
            422 => 'ApiValidationErrorResponse',
            429 => 'ApiRateLimitResponse',
            500 => 'ApiServerErrorResponse',
            503 => 'ApiServiceUnavailableResponse',
        ];
        $expectedComponentNames = [
            ...array_values($expectedComponents),
            'ApiFileDeleteFailedResponse',
            'ApiManagedSystemSettingConflictResponse',
        ];

        $this->assertSame(
            $expectedComponentNames,
            array_keys($document['components']['responses']),
        );

        foreach ($expectedComponents as $status => $component) {
            $response = $document['components']['responses'][$component];
            $schema = $response['content']['application/json']['schema'];
            $required = ['success', 'code', 'message', 'data', 'errors', 'request_id'];

            if ($status === 503) {
                $required[] = 'error_code';
            }

            $this->assertSame($required, $schema['required']);
            $this->assertSame([false], $schema['properties']['success']['enum']);
            $this->assertSame($status, $schema['properties']['code']['const']);
            $this->assertStrictEmptyObjectSchema($schema['properties']['data']);
            $this->assertSame('string', $response['headers']['X-Request-Id']['schema']['type']);

            if ($status === 422) {
                $this->assertSame('object', $schema['properties']['errors']['type']);
                $this->assertSame('array', $schema['properties']['errors']['additionalProperties']['type']);
                $this->assertSame('string', $schema['properties']['errors']['additionalProperties']['items']['type']);
            } else {
                $this->assertStrictEmptyObjectSchema($schema['properties']['errors']);
            }
        }

        foreach ($document['paths'] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                foreach ($operation['responses'] as $status => $response) {
                    $resolved = isset($response['$ref'])
                        ? $document['components']['responses'][str($response['$ref'])->afterLast('/')->toString()]
                        : $response;

                    $this->assertArrayHasKey(
                        'X-Request-Id',
                        $resolved['headers'] ?? [],
                        "{$method} {$path} response {$status} must document X-Request-Id.",
                    );
                }

                $this->assertSame(
                    '#/components/responses/ApiServerErrorResponse',
                    $operation['responses']['500']['$ref'] ?? null,
                    "{$method} {$path} must document HTTP 500.",
                );
            }
        }
    }

    public function test_member_auth_operations_document_actual_error_boundaries(): void
    {
        $document = $this->openApiDocument();
        $operations = [
            [ApiRouting::path('/auth/login'), 'post'],
            [ApiRouting::path('/auth/refresh'), 'post'],
            [ApiRouting::path('/auth/me'), 'get'],
            [ApiRouting::path('/auth/password'), 'put'],
            [ApiRouting::path('/auth/logout'), 'post'],
        ];

        foreach ($operations as [$path, $method]) {
            $responses = $document['paths'][$path][$method]['responses'];
            $this->assertSame('#/components/responses/ApiUnauthorizedResponse', $responses['401']['$ref'] ?? null);
            $this->assertSame('#/components/responses/ApiRateLimitResponse', $responses['429']['$ref'] ?? null);
            $this->assertSame('#/components/responses/ApiServerErrorResponse', $responses['500']['$ref'] ?? null);
        }

        foreach ([
            [ApiRouting::path('/auth/refresh'), 'post'],
            [ApiRouting::path('/auth/me'), 'get'],
            [ApiRouting::path('/auth/password'), 'put'],
            [ApiRouting::path('/auth/logout'), 'post'],
        ] as [$path, $method]) {
            $this->assertSame(
                '#/components/responses/ApiForbiddenResponse',
                $document['paths'][$path][$method]['responses']['403']['$ref'] ?? null,
            );
        }

        foreach ([[ApiRouting::path('/auth/login'), 'post'], [ApiRouting::path('/auth/password'), 'put']] as [$path, $method]) {
            $this->assertSame(
                '#/components/responses/ApiValidationErrorResponse',
                $document['paths'][$path][$method]['responses']['422']['$ref'] ?? null,
            );
        }

        foreach ([
            [ApiRouting::path('/auth/login'), 'post'],
            [ApiRouting::path('/auth/refresh'), 'post'],
            [ApiRouting::path('/auth/password'), 'put'],
            [ApiRouting::path('/auth/logout'), 'post'],
        ] as [$path, $method]) {
            $this->assertSame(
                '#/components/responses/ApiContentTooLargeResponse',
                $document['paths'][$path][$method]['responses']['413']['$ref'] ?? null,
            );
        }
    }

    public function test_generated_openapi_document_uses_precise_admin_permission_names_schema(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            [ApiRouting::path('/admin/auth/login'), 'post'],
            [ApiRouting::path('/admin/auth/me'), 'get'],
            [ApiRouting::path('/admin/auth/refresh'), 'post'],
        ] as [$path, $method]) {
            $permissionNames = $document['paths'][$path][$method]['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['permission_names'];

            $this->assertSame('array', $permissionNames['type'] ?? null, "{$path} permission_names must be documented as an array.");
            $this->assertSame(['type' => 'string'], $permissionNames['items'] ?? null, "{$path} permission_names items must be documented as strings.");
            $this->assertArrayNotHasKey('anyOf', $permissionNames, "{$path} permission_names must not fall back to an ambiguous union.");
        }
    }

    public function test_generated_openapi_document_uses_pagination_metadata_for_paginated_admin_indexes(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            ApiRouting::path('/admin/users'),
            ApiRouting::path('/admin/members'),
            ApiRouting::path('/admin/dictionary-types'),
            ApiRouting::path('/admin/dictionary-items'),
            ApiRouting::path('/admin/system-configs'),
            ApiRouting::path('/admin/activity-logs'),
            ApiRouting::path('/admin/login-logs'),
        ] as $path) {
            $schema = $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

            $this->assertSame(['success', 'code', 'message', 'data', 'meta', 'request_id'], $schema['required'], "{$path} must document pagination meta.");
            $this->assertArrayHasKey('meta', $schema['properties'], "{$path} must include pagination meta property.");
            $this->assertSame(
                ['pagination', 'page', 'page_size', 'has_more', 'total'],
                $schema['properties']['meta']['required'],
                "{$path} must document the business pagination metadata shape.",
            );
        }
    }

    public function test_generated_openapi_document_separates_profile_updates_from_password_operations(): void
    {
        $document = $this->openApiDocument();
        $schemas = $document['components']['schemas'];

        $this->assertArrayNotHasKey('password', $schemas['UpdateUserRequest']['properties']);

        foreach ([
            ApiRouting::path('/admin/auth/password') => ['current_password', 'password', 'password_confirmation'],
            ApiRouting::path('/auth/password') => ['current_password', 'password', 'password_confirmation'],
            ApiRouting::path('/admin/users/{user}/password') => ['password', 'password_confirmation'],
        ] as $path => $required) {
            $reference = $document['paths'][$path]['put']['requestBody']['content']['application/json']['schema']['$ref'];
            $schemaName = str($reference)->afterLast('/')->toString();

            $this->assertSame($required, $schemas[$schemaName]['required']);
            $this->assertSame(8, $schemas[$schemaName]['properties']['password']['minLength']);
            $this->assertSame(255, $schemas[$schemaName]['properties']['password']['maxLength']);
            $this->assertSame(8, $schemas[$schemaName]['properties']['password_confirmation']['minLength']);
            $this->assertSame(255, $schemas[$schemaName]['properties']['password_confirmation']['maxLength']);
        }

        $this->assertSame(8, $schemas['StoreUserRequest']['properties']['password']['minLength']);
        $this->assertSame(255, $schemas['StoreUserRequest']['properties']['password']['maxLength']);
    }

    public function test_generated_openapi_document_keeps_bounded_admin_catalogs_unpaginated(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            ApiRouting::path('/admin/roles'),
            ApiRouting::path('/admin/permissions'),
            ApiRouting::path('/admin/menus'),
            ApiRouting::path('/admin/menus/tree'),
        ] as $path) {
            $schema = $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

            $this->assertSame(['success', 'code', 'message', 'data', 'request_id'], $schema['required'], "{$path} must remain a bounded catalog response.");
            $this->assertArrayNotHasKey('meta', $schema['properties'], "{$path} must not document pagination meta.");
        }
    }

    public function test_generated_openapi_log_contract_uses_precise_date_ranges_bigint_ids_and_nullability(): void
    {
        $document = $this->openApiDocument();

        foreach ([ApiRouting::path('/admin/activity-logs'), ApiRouting::path('/admin/login-logs')] as $path) {
            $parameters = collect($document['paths'][$path]['get']['parameters'])->keyBy('name');
            $createdAt = $parameters['created_at']['schema'];

            $this->assertSame('array', $createdAt['type']);
            $this->assertSame(2, $createdAt['minItems']);
            $this->assertSame(2, $createdAt['maxItems']);
            $this->assertSame(['type' => 'string', 'format' => 'date'], $createdAt['items']);
            $this->assertSame('integer', $parameters['subject_id']['schema']['type']);
            $this->assertSame('int64', $parameters['subject_id']['schema']['format']);
        }

        $activityParameters = collect($document['paths'][ApiRouting::path('/admin/activity-logs')]['get']['parameters'])->keyBy('name');
        $this->assertSame('integer', $activityParameters['causer_id']['schema']['type']);
        $this->assertSame('int64', $activityParameters['causer_id']['schema']['format']);

        $activity = $document['components']['schemas']['ActivityLogResource']['properties'];
        $login = $document['components']['schemas']['LoginLogResource']['properties'];

        foreach ([$activity['id'], $login['id']] as $idSchema) {
            $this->assertSame('integer', $idSchema['type']);
            $this->assertSame('int64', $idSchema['format']);
        }

        foreach (['subject_id', 'causer_id'] as $property) {
            $this->assertSame(['integer', 'null'], $activity[$property]['type']);
            $this->assertSame('int64', $activity[$property]['format']);
        }

        foreach (['log_name', 'event', 'subject_type', 'causer_type'] as $property) {
            $this->assertSame(['string', 'null'], $activity[$property]['type']);
        }

        $this->assertSame(['integer', 'null'], $login['subject_id']['type']);
        $this->assertSame('int64', $login['subject_id']['format']);
    }

    public function test_generated_openapi_dictionary_and_system_config_value_contracts_are_precise(): void
    {
        $schemas = $this->openApiDocument()['components']['schemas'];

        foreach (['DictionaryItemResource', 'StoreDictionaryItemRequest', 'UpdateDictionaryItemRequest'] as $schemaName) {
            $meta = $schemas[$schemaName]['properties']['meta'];

            $this->assertSame(['object', 'null'], $meta['type']);
            $this->assertSame([], $meta['additionalProperties']);
        }

        foreach (['StoreSystemConfigRequest', 'UpdateSystemConfigRequest'] as $schemaName) {
            $value = $schemas[$schemaName]['properties']['value'];

            $this->assertSame(['string', 'null'], $value['type']);
            $this->assertSame(10000, $value['maxLength']);
        }

        $resolvedValueTypes = collect($schemas['SystemConfigResource']['properties']['value']['anyOf'])
            ->pluck('type')
            ->all();
        $this->assertEqualsCanonicalizing([
            'string',
            'integer',
            'number',
            'boolean',
            'object',
            'array',
            'null',
        ], $resolvedValueTypes);
    }

    public function test_generated_openapi_menu_contract_uses_permission_collections_only(): void
    {
        $schemas = $this->openApiDocument()['components']['schemas'];
        $menuSchema = $schemas['MenuResource'];

        $this->assertSame(['permission_ids', 'permission_names', 'permissions'], array_values(array_intersect(
            $menuSchema['required'],
            ['permission_ids', 'permission_names', 'permissions'],
        )));
        $this->assertSame(['type' => 'integer', 'format' => 'int64'], $menuSchema['properties']['permission_ids']['items']);
        $this->assertSame(['type' => 'string'], $menuSchema['properties']['permission_names']['items']);
        $this->assertSame('#/components/schemas/PermissionResource', $menuSchema['properties']['permissions']['items']['$ref']);
        $this->assertArrayNotHasKey('seed_key', $menuSchema['properties']);

        foreach (['StoreMenuRequest', 'UpdateMenuRequest'] as $schemaName) {
            $properties = $schemas[$schemaName]['properties'];

            $this->assertArrayHasKey('permission_ids', $properties);
            $this->assertArrayNotHasKey('permission_id', $properties);
            $this->assertArrayNotHasKey('permission_name', $properties);
            $this->assertArrayNotHasKey('seed_key', $properties);
        }
    }

    public function test_generated_openapi_management_create_and_delete_operations_keep_http_200(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            [ApiRouting::path('/admin/users'), 'post'],
            [ApiRouting::path('/admin/members'), 'post'],
            [ApiRouting::path('/admin/users/{user}'), 'delete'],
            [ApiRouting::path('/admin/roles'), 'post'],
            [ApiRouting::path('/admin/roles/{role}'), 'delete'],
            [ApiRouting::path('/admin/permissions'), 'post'],
            [ApiRouting::path('/admin/permissions/{permission}'), 'delete'],
            [ApiRouting::path('/admin/menus'), 'post'],
            [ApiRouting::path('/admin/menus/{menu}'), 'delete'],
            [ApiRouting::path('/admin/dictionary-types'), 'post'],
            [ApiRouting::path('/admin/dictionary-types/{dictionaryType}'), 'delete'],
            [ApiRouting::path('/admin/dictionary-items'), 'post'],
            [ApiRouting::path('/admin/dictionary-items/{dictionaryItem}'), 'delete'],
            [ApiRouting::path('/admin/system-configs'), 'post'],
            [ApiRouting::path('/admin/system-configs/{systemConfig}'), 'delete'],
        ] as [$path, $method]) {
            $responses = $document['paths'][$path][$method]['responses'];

            $this->assertArrayHasKey('200', $responses, "{$method} {$path} must document HTTP 200.");
            $this->assertArrayNotHasKey('201', $responses, "{$method} {$path} must not document HTTP 201.");
            $this->assertArrayNotHasKey('204', $responses, "{$method} {$path} must not document HTTP 204.");
        }
    }

    public function test_generated_openapi_document_exposes_exact_member_management_contract(): void
    {
        $document = $this->openApiDocument();
        $operations = $this->operationsById($document);

        foreach ([
            'admin.members.index',
            'admin.members.store',
            'admin.members.show',
            'admin.members.update',
            'admin.members.update-status',
            'admin.members.reset-password',
            'admin.members.invalidate-sessions',
        ] as $operationId) {
            $this->assertArrayHasKey($operationId, $operations);
        }

        $memberReference = $document['paths'][ApiRouting::path('/admin/members')]['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'];
        $memberSchema = $document['components']['schemas'][str($memberReference)->afterLast('/')->toString()];
        $this->assertSame([
            'id',
            'name',
            'email',
            'mobile',
            'is_active',
            'last_login_at',
            'last_login_ip',
            'created_at',
            'updated_at',
        ], $memberSchema['required']);
        $this->assertSame($memberSchema['required'], array_keys($memberSchema['properties']));
        $this->assertArrayNotHasKey('password', $memberSchema['properties']);
        $this->assertArrayNotHasKey('auth_version', $memberSchema['properties']);

        $publicMember = $document['components']['schemas']['MemberResource'];
        $this->assertSame([
            'id',
            'name',
            'email',
            'mobile',
            'is_active',
            'last_login_at',
        ], $publicMember['required']);
        $this->assertSame($publicMember['required'], array_keys($publicMember['properties']));

        $storeMember = $document['components']['schemas']['StoreMemberRequest'];
        $storeMemberProperties = $storeMember['allOf'][0];
        $identityOptions = $storeMember['allOf'][1]['anyOf'];
        $this->assertSame(['name', 'password', 'password_confirmation'], $storeMemberProperties['required']);
        $this->assertSame(1, $storeMemberProperties['properties']['name']['minLength']);
        $this->assertSame(255, $storeMemberProperties['properties']['name']['maxLength']);
        $this->assertSame(8, $storeMemberProperties['properties']['password']['minLength']);
        $this->assertSame(255, $storeMemberProperties['properties']['password']['maxLength']);
        $this->assertSame(['email'], $identityOptions[0]['required']);
        $this->assertSame('string', $identityOptions[0]['properties']['email']['type']);
        $this->assertSame('email', $identityOptions[0]['properties']['email']['format']);
        $this->assertSame(1, $identityOptions[0]['properties']['email']['minLength']);
        $this->assertSame('.*\\S.*', $identityOptions[0]['properties']['email']['pattern']);
        $this->assertSame(['mobile'], $identityOptions[1]['required']);
        $this->assertSame('string', $identityOptions[1]['properties']['mobile']['type']);
        $this->assertSame(1, $identityOptions[1]['properties']['mobile']['minLength']);
        $this->assertSame('.*\\S.*', $identityOptions[1]['properties']['mobile']['pattern']);

        $updateMember = $document['components']['schemas']['UpdateMemberRequest'];
        $this->assertSame(['name', 'email', 'mobile'], array_keys($updateMember['properties']));
        $this->assertArrayNotHasKey('required', $updateMember);
        $this->assertSame(1, $updateMember['properties']['name']['minLength']);
        $this->assertSame(255, $updateMember['properties']['name']['maxLength']);
        $this->assertSame(['string', 'null'], $updateMember['properties']['email']['type']);
        $this->assertSame('email', $updateMember['properties']['email']['format']);
        $this->assertSame(255, $updateMember['properties']['email']['maxLength']);
        $this->assertSame(['string', 'null'], $updateMember['properties']['mobile']['type']);
        $this->assertSame(32, $updateMember['properties']['mobile']['maxLength']);

        $updateStatus = $document['components']['schemas']['UpdateMemberStatusRequest'];
        $this->assertSame(['is_active'], array_keys($updateStatus['properties']));
        $this->assertSame(['is_active'], $updateStatus['required']);
        $this->assertSame('boolean', $updateStatus['properties']['is_active']['type']);

        $resetPassword = $document['components']['schemas']['ResetMemberPasswordRequest'];
        $this->assertSame(['password', 'password_confirmation'], array_keys($resetPassword['properties']));
        $this->assertSame(['password', 'password_confirmation'], $resetPassword['required']);
        foreach ($resetPassword['properties'] as $passwordField) {
            $this->assertSame(8, $passwordField['minLength']);
            $this->assertSame(255, $passwordField['maxLength']);
        }

        $this->assertArrayNotHasKey(
            'requestBody',
            $document['paths'][ApiRouting::path('/admin/members/{member}/invalidate-sessions')]['post'],
        );

        $indexParameters = collect($document['paths'][ApiRouting::path('/admin/members')]['get']['parameters'])->pluck('name')->all();
        foreach (['page', 'per_page', 'search', 'is_active'] as $parameter) {
            $this->assertContains($parameter, $indexParameters);
        }
    }

    public function test_generated_openapi_document_exposes_exact_system_settings_contract(): void
    {
        $document = $this->openApiDocument();
        $operations = $this->operationsById($document);
        $operationIds = [
            'system-settings.public',
            'admin.system-settings.show',
            'admin.system-settings.basic.update',
            'admin.system-settings.branding.update',
        ];

        foreach ($operationIds as $operationId) {
            $this->assertArrayHasKey($operationId, $operations);
        }

        $this->assertSame([['http' => []]], $document['security']);
        $this->assertSame([], $operations['system-settings.public']['security']);
        $this->assertSame(
            '#/components/responses/ApiRateLimitResponse',
            $operations['system-settings.public']['responses']['429']['$ref'],
        );
        foreach (array_slice($operationIds, 1) as $operationId) {
            $this->assertArrayNotHasKey('security', $operations[$operationId]);
            $this->assertSame('#/components/responses/ApiUnauthorizedResponse', $operations[$operationId]['responses']['401']['$ref']);
            $this->assertSame('#/components/responses/ApiForbiddenResponse', $operations[$operationId]['responses']['403']['$ref']);
        }
        foreach ($operationIds as $operationId) {
            $this->assertSame(
                '#/components/schemas/SystemSettingsResource',
                $operations[$operationId]['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'],
            );
        }
        $this->assertSame(
            '#/components/schemas/UpdateBasicSystemSettingsRequest',
            $operations['admin.system-settings.basic.update']['requestBody']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/UpdateBrandingSystemSettingsRequest',
            $operations['admin.system-settings.branding.update']['requestBody']['content']['application/json']['schema']['$ref'],
        );

        $settings = $document['components']['schemas']['SystemSettingsResource'];
        $this->assertSame(['basic', 'branding'], $settings['required']);
        $this->assertSame(
            ['system_name', 'copyright', 'icp_filing_number'],
            $settings['properties']['basic']['required'],
        );
        $this->assertSame(
            ['navigation_logo_url', 'login_logo_url', 'login_background_url', 'favicon_url'],
            $settings['properties']['branding']['required'],
        );
        $this->assertSame(
            $settings['properties']['basic']['required'],
            array_keys($settings['properties']['basic']['properties']),
        );
        $this->assertSame(
            $settings['properties']['branding']['required'],
            array_keys($settings['properties']['branding']['properties']),
        );
        foreach ($settings['properties']['basic']['required'] as $field) {
            $this->assertSame(['string', 'null'], $settings['properties']['basic']['properties'][$field]['type']);
        }
        foreach ($settings['properties']['branding']['required'] as $field) {
            $this->assertSame(['string', 'null'], $settings['properties']['branding']['properties'][$field]['type']);
            $this->assertSame('uri', $settings['properties']['branding']['properties'][$field]['format']);
            $this->assertSame(2048, $settings['properties']['branding']['properties'][$field]['maxLength']);
        }

        $basicRequest = $document['components']['schemas']['UpdateBasicSystemSettingsRequest'];
        $this->assertSame(['system_name', 'copyright', 'icp_filing_number'], $basicRequest['required']);
        $this->assertSame($basicRequest['required'], array_keys($basicRequest['properties']));
        $this->assertSame('string', $basicRequest['properties']['system_name']['type']);
        $this->assertSame(['string', 'null'], $basicRequest['properties']['copyright']['type']);
        $this->assertSame(['string', 'null'], $basicRequest['properties']['icp_filing_number']['type']);

        $brandingRequest = $document['components']['schemas']['UpdateBrandingSystemSettingsRequest'];
        $this->assertSame([
            'navigation_logo_url',
            'login_logo_url',
            'login_background_url',
            'favicon_url',
        ], $brandingRequest['required']);
        $this->assertSame($brandingRequest['required'], array_keys($brandingRequest['properties']));
        foreach ($brandingRequest['required'] as $field) {
            $this->assertSame(['string', 'null'], $brandingRequest['properties'][$field]['type']);
            $this->assertSame('uri', $brandingRequest['properties'][$field]['format']);
            $this->assertSame(2048, $brandingRequest['properties'][$field]['maxLength']);
        }

        foreach ([
            ['path' => '/admin/system-configs', 'method' => 'post'],
            ['path' => '/admin/system-configs/{systemConfig}', 'method' => 'put'],
            ['path' => '/admin/system-configs/{systemConfig}', 'method' => 'delete'],
        ] as $operation) {
            $conflict = $document['paths'][ApiRouting::path($operation['path'])][$operation['method']]['responses']['409'];
            $this->assertSame('#/components/responses/ApiManagedSystemSettingConflictResponse', $conflict['$ref']);
        }
        $managedConflict = $document['components']['responses']['ApiManagedSystemSettingConflictResponse']['content']['application/json']['schema'];
        $this->assertSame(409, $managedConflict['properties']['code']['const']);
        $this->assertContains('error_code', $managedConflict['required']);
        $this->assertSame(['managed_system_setting_immutable'], $managedConflict['properties']['error_code']['enum']);

        $fileDelete = $document['paths'][ApiRouting::path('/admin/files/{file}')]['delete']['responses']['503'];
        $this->assertSame('#/components/responses/ApiFileDeleteFailedResponse', $fileDelete['$ref']);
        $fileDeleteFailure = $document['components']['responses']['ApiFileDeleteFailedResponse']['content']['application/json']['schema'];
        $this->assertSame(['file_delete_failed'], $fileDeleteFailure['properties']['error_code']['enum']);
    }

    public function test_generated_openapi_document_exposes_exact_file_management_contract(): void
    {
        $document = $this->openApiDocument();
        $operations = $this->operationsById($document);

        foreach (['admin.files.index', 'admin.files.store', 'admin.files.destroy'] as $operationId) {
            $this->assertArrayHasKey($operationId, $operations);
        }

        $path = ApiRouting::path('/admin/files');
        $requestBody = $document['paths'][$path]['post']['requestBody'];
        $this->assertSame(['multipart/form-data'], array_keys($requestBody['content']));
        $requestReference = $requestBody['content']['multipart/form-data']['schema']['$ref'];
        $requestSchema = $document['components']['schemas'][str($requestReference)->afterLast('/')->toString()];
        $this->assertSame(['file'], $requestSchema['required']);
        $this->assertSame('binary', $requestSchema['properties']['file']['format']);
        $this->assertArrayNotHasKey('type', $requestSchema['properties']);
        $this->assertSame(
            app(FileUploadPolicy::class)->openApiDescription(),
            $requestSchema['properties']['file']['description'],
        );
        $this->assertStringContainsString('image (JPG, JPEG, PNG, WEBP, GIF; max 5 MiB)', $requestSchema['properties']['file']['description']);
        $this->assertStringContainsString('document (PDF, TXT, CSV; max 20 MiB)', $requestSchema['properties']['file']['description']);
        $this->assertStringContainsString('video (MP4; max 100 MiB)', $requestSchema['properties']['file']['description']);
        $this->assertStringContainsString('100 MiB', $requestSchema['properties']['file']['description']);

        $fileReference = $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'];
        $fileSchema = $document['components']['schemas'][str($fileReference)->afterLast('/')->toString()];
        $this->assertSame([
            'id',
            'name',
            'type',
            'mime_type',
            'extension',
            'size',
            'url',
            'width',
            'height',
            'status',
            'created_at',
        ], $fileSchema['required']);
        $this->assertSame(app(FileUploadPolicy::class)->types(), $fileSchema['properties']['type']['enum']);
        $this->assertSame(['image', 'document', 'video', 'audio', 'other'], $fileSchema['properties']['type']['enum']);
        $this->assertSame(['pending', 'ready', 'failed'], $fileSchema['properties']['status']['enum']);
        foreach (['disk', 'path', 'created_by'] as $internalProperty) {
            $this->assertArrayNotHasKey($internalProperty, $fileSchema['properties']);
        }

        $deleteResponse = $document['paths'][ApiRouting::path('/admin/files/{file}')]['delete']['responses']['503'];
        $this->assertSame('#/components/responses/ApiFileDeleteFailedResponse', $deleteResponse['$ref']);
        $fileDeleteFailed = $document['components']['responses']['ApiFileDeleteFailedResponse']['content']['application/json']['schema'];
        $this->assertSame(['file_delete_failed'], $fileDeleteFailed['properties']['error_code']['enum']);

    }

    /**
     * @return array<string, mixed>
     */
    private function openApiDocument(): array
    {
        $path = base_path('docs/api.json');

        $this->assertFileExists($path, 'Run composer docs:api before running the OpenAPI documentation tests.');

        $document = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($document);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaContainsType(array $schema, string $type): bool
    {
        if (($schema['type'] ?? null) === $type) {
            return true;
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $combinedSchemaKey) {
            if (! isset($schema[$combinedSchemaKey]) || ! is_array($schema[$combinedSchemaKey])) {
                continue;
            }

            foreach ($schema[$combinedSchemaKey] as $combinedSchema) {
                if (is_array($combinedSchema) && $this->schemaContainsType($combinedSchema, $type)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertStrictEmptyObjectSchema(array $schema): void
    {
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertSame([], $schema['properties'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? null);
        $this->assertSame(0, $schema['maxProperties'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, mixed>>
     */
    private function operationsById(array $document): array
    {
        return collect($document['paths'])
            ->flatMap(fn (array $path): array => collect($path)
                ->filter(fn (array $operation): bool => isset($operation['operationId']))
                ->keyBy('operationId')
                ->all())
            ->all();
    }
}
