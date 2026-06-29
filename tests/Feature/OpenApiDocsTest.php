<?php

namespace Tests\Feature;

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
            '/api/admin/menus/tree' => 'get',
            '/api/admin/users' => 'get',
            '/api/admin/roles' => 'get',
            '/api/admin/permissions' => 'get',
            '/api/admin/dictionary-types' => 'get',
            '/api/admin/system-configs' => 'get',
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

    public function test_generated_openapi_document_uses_pagination_metadata_for_paginated_admin_indexes(): void
    {
        $document = $this->openApiDocument();

        foreach ([
            '/api/admin/users',
            '/api/admin/dictionary-types',
            '/api/admin/dictionary-items',
            '/api/admin/system-configs',
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
}
