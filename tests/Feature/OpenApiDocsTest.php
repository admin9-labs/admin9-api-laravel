<?php

namespace Tests\Feature;

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
            '/api/admin/auth/login' => 'post',
            '/api/admin/auth/me' => 'get',
            '/api/admin/auth/password' => 'put',
            '/api/admin/menus/tree' => 'get',
            '/api/admin/users' => 'get',
            '/api/admin/members' => 'get',
            '/api/admin/media' => 'get',
            '/api/admin/users/{user}/password' => 'put',
            '/api/admin/roles' => 'get',
            '/api/admin/permissions' => 'get',
            '/api/admin/dictionary-types' => 'get',
            '/api/admin/system-configs' => 'get',
            '/api/admin/activity-logs' => 'get',
            '/api/admin/login-logs' => 'get',
            '/api/auth/password' => 'put',
        ] as $path => $method) {
            $this->assertArrayHasKey($path, $document['paths']);
            $this->assertArrayHasKey($method, $document['paths'][$path]);
        }
    }

    public function test_generated_openapi_document_uses_business_response_envelope_and_filters(): void
    {
        $document = $this->openApiDocument();
        $loginResponseSchema = $document['paths']['/api/admin/auth/login']['post']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(['success', 'code', 'message', 'data', 'request_id'], $loginResponseSchema['required']);
        $this->assertArrayHasKey('success', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('code', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('message', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('data', $loginResponseSchema['properties']);
        $this->assertArrayHasKey('request_id', $loginResponseSchema['properties']);

        $systemConfigParameters = collect($document['paths']['/api/admin/system-configs']['get']['parameters'])
            ->pluck('name')
            ->all();

        foreach (['key', 'name', 'config_group', 'type', 'is_public', 'is_active', 'keyword', 'sort', 'page_size', 'page'] as $parameter) {
            $this->assertContains($parameter, $systemConfigParameters);
        }

        $activityLogParameters = collect($document['paths']['/api/admin/activity-logs']['get']['parameters'])
            ->pluck('name')
            ->all();
        $loginLogParameters = collect($document['paths']['/api/admin/login-logs']['get']['parameters'])
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
            '/api/auth/login',
            '/api/auth/refresh',
            '/api/admin/auth/login',
            '/api/admin/auth/refresh',
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

        foreach (['/api/auth/refresh', '/api/admin/auth/refresh'] as $path) {
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
            ['/api/auth/login', 'post'],
            ['/api/auth/refresh', 'post'],
            ['/api/auth/me', 'get'],
            ['/api/auth/password', 'put'],
            ['/api/auth/logout', 'post'],
            ['/api/admin/auth/login', 'post'],
            ['/api/admin/media', 'post'],
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

        $this->assertSame(
            array_values($expectedComponents),
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
            ['/api/auth/login', 'post'],
            ['/api/auth/refresh', 'post'],
            ['/api/auth/me', 'get'],
            ['/api/auth/password', 'put'],
            ['/api/auth/logout', 'post'],
        ];

        foreach ($operations as [$path, $method]) {
            $responses = $document['paths'][$path][$method]['responses'];
            $this->assertSame('#/components/responses/ApiUnauthorizedResponse', $responses['401']['$ref'] ?? null);
            $this->assertSame('#/components/responses/ApiRateLimitResponse', $responses['429']['$ref'] ?? null);
            $this->assertSame('#/components/responses/ApiServerErrorResponse', $responses['500']['$ref'] ?? null);
        }

        foreach ([
            ['/api/auth/refresh', 'post'],
            ['/api/auth/me', 'get'],
            ['/api/auth/password', 'put'],
            ['/api/auth/logout', 'post'],
        ] as [$path, $method]) {
            $this->assertSame(
                '#/components/responses/ApiForbiddenResponse',
                $document['paths'][$path][$method]['responses']['403']['$ref'] ?? null,
            );
        }

        foreach ([['/api/auth/login', 'post'], ['/api/auth/password', 'put']] as [$path, $method]) {
            $this->assertSame(
                '#/components/responses/ApiValidationErrorResponse',
                $document['paths'][$path][$method]['responses']['422']['$ref'] ?? null,
            );
        }

        foreach ([
            ['/api/auth/login', 'post'],
            ['/api/auth/refresh', 'post'],
            ['/api/auth/password', 'put'],
            ['/api/auth/logout', 'post'],
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
            ['/api/admin/auth/login', 'post'],
            ['/api/admin/auth/me', 'get'],
            ['/api/admin/auth/refresh', 'post'],
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
            '/api/admin/users',
            '/api/admin/members',
            '/api/admin/media',
            '/api/admin/dictionary-types',
            '/api/admin/dictionary-items',
            '/api/admin/system-configs',
            '/api/admin/activity-logs',
            '/api/admin/login-logs',
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
            '/api/admin/auth/password' => ['current_password', 'password', 'password_confirmation'],
            '/api/auth/password' => ['current_password', 'password', 'password_confirmation'],
            '/api/admin/users/{user}/password' => ['password', 'password_confirmation'],
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
            '/api/admin/roles',
            '/api/admin/permissions',
            '/api/admin/menus',
            '/api/admin/menus/tree',
        ] as $path) {
            $schema = $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

            $this->assertSame(['success', 'code', 'message', 'data', 'request_id'], $schema['required'], "{$path} must remain a bounded catalog response.");
            $this->assertArrayNotHasKey('meta', $schema['properties'], "{$path} must not document pagination meta.");
        }
    }

    public function test_generated_openapi_log_contract_uses_precise_date_ranges_bigint_ids_and_nullability(): void
    {
        $document = $this->openApiDocument();

        foreach (['/api/admin/activity-logs', '/api/admin/login-logs'] as $path) {
            $parameters = collect($document['paths'][$path]['get']['parameters'])->keyBy('name');
            $createdAt = $parameters['created_at']['schema'];

            $this->assertSame('array', $createdAt['type']);
            $this->assertSame(2, $createdAt['minItems']);
            $this->assertSame(2, $createdAt['maxItems']);
            $this->assertSame(['type' => 'string', 'format' => 'date'], $createdAt['items']);
            $this->assertSame('integer', $parameters['subject_id']['schema']['type']);
            $this->assertSame('int64', $parameters['subject_id']['schema']['format']);
        }

        $activityParameters = collect($document['paths']['/api/admin/activity-logs']['get']['parameters'])->keyBy('name');
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

        foreach (['StoreMenuRequest', 'UpdateMenuRequest'] as $schemaName) {
            $properties = $schemas[$schemaName]['properties'];

            $this->assertArrayHasKey('permission_ids', $properties);
            $this->assertArrayNotHasKey('permission_id', $properties);
            $this->assertArrayNotHasKey('permission_name', $properties);
        }
    }

    public function test_generated_openapi_management_create_and_delete_operations_keep_http_200(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            ['/api/admin/users', 'post'],
            ['/api/admin/members', 'post'],
            ['/api/admin/media', 'post'],
            ['/api/admin/media/{media}', 'delete'],
            ['/api/admin/users/{user}', 'delete'],
            ['/api/admin/roles', 'post'],
            ['/api/admin/roles/{role}', 'delete'],
            ['/api/admin/permissions', 'post'],
            ['/api/admin/permissions/{permission}', 'delete'],
            ['/api/admin/menus', 'post'],
            ['/api/admin/menus/{menu}', 'delete'],
            ['/api/admin/dictionary-types', 'post'],
            ['/api/admin/dictionary-types/{dictionaryType}', 'delete'],
            ['/api/admin/dictionary-items', 'post'],
            ['/api/admin/dictionary-items/{dictionaryItem}', 'delete'],
            ['/api/admin/system-configs', 'post'],
            ['/api/admin/system-configs/{systemConfig}', 'delete'],
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

        $memberReference = $document['paths']['/api/admin/members']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'];
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
            $document['paths']['/api/admin/members/{member}/invalidate-sessions']['post'],
        );

        $indexParameters = collect($document['paths']['/api/admin/members']['get']['parameters'])->pluck('name')->all();
        foreach (['page', 'per_page', 'search', 'is_active'] as $parameter) {
            $this->assertContains($parameter, $indexParameters);
        }
    }

    public function test_generated_openapi_document_exposes_exact_media_management_contract(): void
    {
        $document = $this->openApiDocument();
        $operations = $this->operationsById($document);

        foreach (['admin.media.index', 'admin.media.store', 'admin.media.destroy'] as $operationId) {
            $this->assertArrayHasKey($operationId, $operations);
        }

        $requestBody = $document['paths']['/api/admin/media']['post']['requestBody'];
        $this->assertSame(['multipart/form-data'], array_keys($requestBody['content']));
        $requestReference = $requestBody['content']['multipart/form-data']['schema']['$ref'];
        $requestSchema = $document['components']['schemas'][str($requestReference)->afterLast('/')->toString()];
        $this->assertSame(['file'], $requestSchema['required']);
        $this->assertSame('string', $requestSchema['properties']['file']['type']);
        $this->assertSame('binary', $requestSchema['properties']['file']['format']);
        $this->assertSame('application/octet-stream', $requestSchema['properties']['file']['contentMediaType']);
        $this->assertStringContainsString('5 MiB', $requestSchema['properties']['file']['description']);

        $mediaReference = $document['paths']['/api/admin/media']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['$ref'];
        $mediaSchema = $document['components']['schemas'][str($mediaReference)->afterLast('/')->toString()];
        $this->assertSame([
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
        ], $mediaSchema['required']);
        $this->assertSame($mediaSchema['required'], array_keys($mediaSchema['properties']));
        foreach (['disk', 'path', 'created_by'] as $internalProperty) {
            $this->assertArrayNotHasKey($internalProperty, $mediaSchema['properties']);
        }
        $this->assertSame(['type' => 'integer', 'format' => 'int64'], $mediaSchema['properties']['id']);
        $this->assertSame(['type' => 'integer', 'format' => 'int64'], $mediaSchema['properties']['size']);
        $this->assertSame(['string', 'null'], $mediaSchema['properties']['url']['type']);
        $this->assertSame('uri', $mediaSchema['properties']['url']['format']);
        $this->assertSame(['integer', 'null'], $mediaSchema['properties']['width']['type']);
        $this->assertSame(['integer', 'null'], $mediaSchema['properties']['height']['type']);
        $this->assertSame(['pending', 'ready', 'failed'], $mediaSchema['properties']['status']['enum']);

        $serviceUnavailableOperations = collect($document['paths'])
            ->flatMap(fn (array $path, string $pathName): array => collect($path)
                ->filter(fn (array $operation): bool => isset($operation['responses']['503']))
                ->map(fn (array $operation, string $method): string => "{$method} {$pathName}")
                ->all())
            ->values()
            ->all();
        $this->assertSame(['delete /api/admin/media/{media}'], $serviceUnavailableOperations);
        $this->assertArrayNotHasKey('422', $document['paths']['/api/admin/media/{media}']['delete']['responses']);

        $serviceUnavailable = $document['components']['responses']['ApiServiceUnavailableResponse']['content']['application/json']['schema'];
        $this->assertSame(['media_delete_failed'], $serviceUnavailable['properties']['error_code']['enum']);
        $this->assertContains('error_code', $serviceUnavailable['required']);
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
