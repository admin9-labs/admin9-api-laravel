<?php

namespace Tests\Feature;

use App\Support\OpenApi\DocumentPruner;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use stdClass;
use Tests\TestCase;

class OpenApiPartitionTest extends TestCase
{
    public function test_independent_documents_keep_transitive_components_and_empty_objects(): void
    {
        $document = OpenApi::make('3.1.0')->setInfo(InfoObject::make('Client'));
        $document->secure(SecurityScheme::http('bearer'));
        $item = new Reference('schemas', 'Item', $document->components);
        $document->components->addSchema('Item', Schema::fromType((new ObjectType)
            ->addProperty('child', $item)
            ->addProperty('data', (new ObjectType)->example(new stdClass))));
        $document->components->addSchema('Unused', Schema::fromType(new StringType));
        $document->components->responses['Items'] = Response::make(200)->setContent('application/json', $item);
        $document->components->responses['UnusedResponse'] = Response::make(400);
        $document->addPath(Path::make('items')->addOperation(
            Operation::make('get')->setOperationId('items.index')
                ->addResponse(new Reference('responses', 'Items', $document->components)),
        ));

        (new DocumentPruner)->prune($document);
        $result = $document->toArray();

        $this->assertSame(['Item'], array_keys($result['components']['schemas']));
        $this->assertSame(['Items'], array_keys($result['components']['responses']));
        $this->assertSame([['http' => []]], $result['security']);
        $this->assertSame('bearer', $result['components']['securitySchemes']['http']['scheme']);
        $this->assertInstanceOf(stdClass::class, $result['components']['schemas']['Item']['properties']['data']['example']);
        $this->assertReferencesResolve($result, $result);
    }

    public function test_committed_documents_cover_all_api_routes_and_resolve_every_reference(): void
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

    public function test_independent_document_pruning_is_stable(): void
    {
        $document = OpenApi::make('3.1.0')->setInfo(InfoObject::make('Client'));
        $document->components->addSchema('Unused', Schema::fromType(new StringType));
        $document->addPath(Path::make('settings')->addOperation(Operation::make('get')->setOperationId('system-settings.public')));
        $pruner = new DocumentPruner;
        $pruner->prune($document);
        $before = $document->toArray();
        $pruner->prune($document);

        $this->assertSame($before, $document->toArray());
        $this->assertFileDoesNotExist(base_path('docs/api.json'));
        $this->assertArrayNotHasKey('docs:api:partition', Artisan::all());
    }

    /** @param array<string, mixed> $document */
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
