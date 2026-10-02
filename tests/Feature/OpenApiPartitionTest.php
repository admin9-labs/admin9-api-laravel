<?php

namespace Tests\Feature;

use App\Support\OpenApi\DocumentPartitioner;
use Illuminate\Support\Facades\Route;
use stdClass;
use Tests\TestCase;

class OpenApiPartitionTest extends TestCase
{
    public function test_partitions_keep_only_their_operations_and_transitive_components(): void
    {
        $document = json_decode(<<<'JSON'
{"openapi":"3.1.0","security":[{"http":[]}],"tags":[{"name":"Admin"},{"name":"Client"},{"name":"Unused"}],"paths":{
"/custom/items":{"get":{"operationId":"items.index","tags":["Client"],"security":[],"responses":{"200":{"$ref":"#/components/responses/Items"}}},"post":{"operationId":"admin.items.store","tags":["Admin"],"responses":{"200":{"content":{"application/json":{"schema":{"$ref":"#/components/schemas/AdminItem"}}}}}}},
"/custom/settings":{"get":{"operationId":"system-settings.public","security":[],"responses":{}}}
},"components":{"securitySchemes":{"http":{"type":"http","scheme":"bearer"}},"responses":{"Items":{"content":{"application/json":{"schema":{"$ref":"#/components/schemas/Item"}}}}},"schemas":{
"Item":{"type":"object","properties":{"child":{"$ref":"#/components/schemas/Item"},"data":{"type":"object","example":{}}}},
"AdminItem":{"type":"object","properties":{"item":{"$ref":"#/components/schemas/Item"}}},"Unused":{"type":"string"}
}}}
JSON, flags: JSON_THROW_ON_ERROR);

        ['admin' => $admin, 'client' => $client] = (new DocumentPartitioner)->partition($document);

        $this->assertSame(['post'], array_keys((array) $admin->paths->{'/custom/items'}));
        $this->assertSame(['get'], array_keys((array) $client->paths->{'/custom/items'}));
        $this->assertSame(['Item', 'AdminItem'], array_keys((array) $admin->components->schemas));
        $this->assertSame(['Item'], array_keys((array) $client->components->schemas));
        $this->assertObjectNotHasProperty('responses', $admin->components);
        $this->assertSame(['Items'], array_keys((array) $client->components->responses));
        $this->assertSame(['Admin'], array_column($admin->tags, 'name'));
        $this->assertSame(['Client'], array_column($client->tags, 'name'));

        foreach ([$admin, $client] as $partition) {
            $this->assertSame($document->security, $partition->security);
            $this->assertEquals($document->components->securitySchemes, $partition->components->securitySchemes);
            $this->assertSame([], $partition->paths->{'/custom/settings'}->get->security);
            $this->assertInstanceOf(stdClass::class, $partition->components->schemas->Item->properties->data->example);
        }

        $this->assertSame(['get', 'post'], array_keys((array) $document->paths->{'/custom/items'}));
    }

    public function test_committed_partitions_cover_all_api_routes_and_resolve_every_reference(): void
    {
        $actual = [];

        foreach (['admin', 'client'] as $audience) {
            $document = json_decode(file_get_contents(base_path("docs/{$audience}-api.json")), true, flags: JSON_THROW_ON_ERROR);

            foreach ($document['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true)) {
                        continue;
                    }
                    $id = $operation['operationId'] ?? $operations['put']['operationId'].'.patch';
                    $actual[$id] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', ltrim($path, '/'));

                    if ($id !== 'system-settings.public') {
                        $this->assertSame($audience === 'admin', str_starts_with($id, 'admin.'), $id);
                    }
                }
            }

            $this->assertReferencesResolve($document, $document);
            $this->assertSame([['http' => []]], $document['security']);
            $this->assertSame('bearer', $document['components']['securitySchemes']['http']['scheme']);
        }

        $expected = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('api', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $id = $route->getName().($method === 'PATCH' && in_array('PUT', $route->methods(), true) ? '.patch' : '');
                $expected[$id] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            }
        }

        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_partition_export_is_stable_and_preserves_the_combined_document(): void
    {
        $before = file_get_contents(base_path('docs/api.json'));
        $this->artisan('docs:api:partition')->assertSuccessful();
        $admin = file_get_contents(base_path('docs/admin-api.json'));
        $client = file_get_contents(base_path('docs/client-api.json'));
        $this->artisan('docs:api:partition')->assertSuccessful();
        $this->assertSame($before, file_get_contents(base_path('docs/api.json')));
        $this->assertSame($admin, file_get_contents(base_path('docs/admin-api.json')));
        $this->assertSame($client, file_get_contents(base_path('docs/client-api.json')));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function assertReferencesResolve(mixed $value, array $document): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertReferencesResolve($child, $document);
            }
        } elseif (is_string($value) && str_starts_with($value, '#/components/')) {
            $target = $document;

            foreach (explode('/', substr($value, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $this->assertArrayHasKey($segment, $target, $value);
                $target = $target[$segment];
            }
        }
    }
}
