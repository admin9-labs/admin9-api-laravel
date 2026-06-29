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
}
