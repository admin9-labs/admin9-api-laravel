<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OpenApiRoutingTest extends TestCase
{
    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryPath = sys_get_temp_dir().'/admin9-openapi-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($this->temporaryPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryPath);
        parent::tearDown();
    }

    /** @param array<string, string> $environment */
    #[DataProvider('routingConfigurations')]
    public function test_independent_documents_describe_real_destinations(array $environment, string $clientOrigin, string $adminOrigin, string $clientPrefix, string $adminPrefix): void
    {
        $script = <<<'PHP_SCRIPT'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = [];
foreach (['client' => 'default', 'admin' => 'admin'] as $audience => $api) {
    $document = app(Dedoc\Scramble\Generator::class)(Dedoc\Scramble\Scramble::getGeneratorConfig($api));
    $result[$audience]['document'] = $document;
    foreach ($document['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            $id = $operation['operationId'] ?? null;
            if (! in_array($id, ['member.auth.login', 'admin.auth.login', 'system-settings.public'], true)) {
                continue;
            }
            $server = ($operation['servers'] ?? $pathItem['servers'] ?? $document['servers'])[0]['url'];
            $url = rtrim($server, '/').$path;
            $matched = app('router')->getRoutes()->match(Illuminate\Http\Request::create($url, strtoupper($method)));
            $result[$audience]['destinations'][$id] = ['url' => $url, 'route' => $matched->getName()];
        }
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP_SCRIPT;
        $process = $this->process([PHP_BINARY, '-r', $script], $environment);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $clientPath = ($clientPrefix === '' ? '' : '/'.$clientPrefix).'/auth/login';
        $adminPath = ($adminPrefix === '' ? '' : '/'.$adminPrefix).'/auth/login';
        $sharedPath = ($clientPrefix === '' ? '' : '/'.$clientPrefix).'/system-settings/public';

        foreach (['client' => [$clientOrigin, $clientPath, 'member.auth.login'], 'admin' => [$adminOrigin, $adminPath, 'admin.auth.login']] as $audience => [$origin, $path, $operationId]) {
            $document = $result[$audience]['document'];
            $this->assertSame([['url' => $origin]], $document['servers']);
            $this->assertSame($operationId, $document['paths'][$path]['post']['operationId']);
            $this->assertSame(['url' => $origin.$path, 'route' => $operationId], $result[$audience]['destinations'][$operationId]);
            $this->assertSame(['url' => $clientOrigin.$sharedPath, 'route' => 'system-settings.public'], $result[$audience]['destinations']['system-settings.public']);
            $this->assertSame([], $document['paths'][$sharedPath]['get']['security']);
            $this->assertSame([['url' => $clientOrigin]], $document['paths'][$sharedPath]['get']['servers']);

            foreach ($document['paths'] as $pathItem) {
                foreach ($pathItem as $operation) {
                    if (isset($operation['operationId']) && $operation['operationId'] !== 'system-settings.public') {
                        $this->assertSame($audience === 'admin', str_starts_with($operation['operationId'], 'admin.'));
                    }
                }
            }
        }
    }

    /** @return array<string, array{array<string, string>, string, string, string, string}> */
    public static function routingConfigurations(): array
    {
        return [
            'unrelated complete prefixes' => [
                ['API_ROUTE_PREFIX' => 'v1', 'ADMIN_API_ROUTE_PREFIX' => 'manage/v2'],
                'http://application.example.test', 'http://application.example.test', 'v1', 'manage/v2',
            ],
            'both domains at root with a non-default port' => [
                ['APP_URL' => 'https://application.example.test:8443/base', 'API_DOMAIN' => 'client.example.test', 'ADMIN_API_DOMAIN' => 'admin.example.test', 'API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'https://client.example.test:8443', 'https://admin.example.test:8443', '', '',
            ],
            'only admin domain at root' => [
                ['ADMIN_API_DOMAIN' => 'admin.example.test', 'API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'http://application.example.test', 'http://admin.example.test', '', '',
            ],
            'only client domain at root' => [
                ['API_DOMAIN' => 'client.example.test', 'API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'http://client.example.test', 'http://application.example.test', '', '',
            ],
            'client at root on shared host' => [
                ['API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => 'manage/v2'],
                'http://application.example.test', 'http://application.example.test', '', 'manage/v2',
            ],
            'admin at root on shared host' => [
                ['API_ROUTE_PREFIX' => 'v1', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'http://application.example.test', 'http://application.example.test', 'v1', '',
            ],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('shadowedCanonicalOrigins')]
    public function test_export_rejects_a_canonical_origin_that_sends_requests_to_the_other_api_without_overwriting_files(array $environment, string $api): void
    {
        $output = $this->temporaryPath.'/existing.json';
        file_put_contents($output, 'existing document');
        $process = $this->process([PHP_BINARY, 'artisan', 'scramble:export', '--api='.$api, '--path='.$output, '--no-interaction'], $environment);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('APP_URL origin', $process->getOutput().$process->getErrorOutput());
        $this->assertSame('existing document', file_get_contents($output));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function shadowedCanonicalOrigins(): array
    {
        return [
            'client login shadowed by admin domain' => [
                ['APP_URL' => 'http://admin.example.test', 'ADMIN_API_DOMAIN' => 'admin.example.test', 'API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'default',
            ],
            'admin login shadowed by client domain' => [
                ['APP_URL' => 'http://client.example.test', 'API_DOMAIN' => 'client.example.test', 'API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'admin',
            ],
            'uppercase canonical hostname is still shadowed' => [
                ['APP_URL' => 'http://ADMIN.EXAMPLE.TEST./', 'ADMIN_API_DOMAIN' => 'admin.example.test', 'API_ROUTE_PREFIX' => '', 'ADMIN_API_ROUTE_PREFIX' => ''],
                'default',
            ],
            'client static password path shadowed by dynamic admin route' => [
                ['APP_URL' => 'http://admin.example.test', 'ADMIN_API_DOMAIN' => 'admin.example.test', 'API_ROUTE_PREFIX' => 'api/users', 'ADMIN_API_ROUTE_PREFIX' => 'api'],
                'default',
            ],
            'admin dynamic password path shadowed by static client route' => [
                ['APP_URL' => 'http://client.example.test', 'API_DOMAIN' => 'client.example.test', 'API_ROUTE_PREFIX' => 'api/users', 'ADMIN_API_ROUTE_PREFIX' => 'api'],
                'admin',
            ],
        ];
    }

    public function test_composer_export_is_stable_under_different_route_settings_and_existing_caches(): void
    {
        $before = [];
        foreach (['client', 'admin'] as $audience) {
            $before[$audience] = file_get_contents(base_path('docs/'.$audience.'-api.json'));
        }

        $cache = $this->temporaryPath.'/foreign-cache.php';
        file_put_contents($cache, '<?php throw new RuntimeException("Foreign cache must not be loaded");');
        $database = $this->temporaryPath.'/docs_test.sqlite';
        touch($database);
        $migration = $this->process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], ['DB_DATABASE' => $database]);
        $migration->run();
        $this->assertTrue($migration->isSuccessful(), $migration->getOutput().$migration->getErrorOutput());
        $workspace = $this->temporaryPath.'/workspace';
        $files = new Filesystem;
        $files->makeDirectory($workspace);
        $files->copy(base_path('composer.json'), $workspace.'/composer.json');
        foreach (['artisan', 'vendor'] as $path) {
            $files->link(base_path($path), $workspace.'/'.$path);
        }
        $process = $this->process(['composer', 'docs:api'], [
            'APP_URL' => 'https://foreign.example.test',
            'API_ROUTE_PREFIX' => 'foreign/client',
            'API_DOMAIN' => 'foreign.example.test',
            'ADMIN_API_ROUTE_PREFIX' => '',
            'ADMIN_API_DOMAIN' => 'foreign.example.test',
            'APP_CONFIG_CACHE' => $cache,
            'APP_ROUTES_CACHE' => $cache,
            'API_VERSION' => 'foreign-version',
            'DB_DATABASE' => $database,
        ]);
        $process->setWorkingDirectory($workspace);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        foreach ($before as $audience => $contents) {
            $this->assertSame($contents, file_get_contents($workspace.'/docs/'.$audience.'-api.json'));
            $this->assertSame($contents, file_get_contents(base_path('docs/'.$audience.'-api.json')));
        }
        $this->assertSame('<?php throw new RuntimeException("Foreign cache must not be loaded");', file_get_contents($cache));
        $this->assertFileDoesNotExist(base_path('docs/api.json'));
    }

    /** @param array<string, string> $environment */
    private function process(array $command, array $environment): Process
    {
        return (new Process($command, base_path(), array_replace([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://application.example.test',
            'API_DOMAIN' => '',
            'ADMIN_API_DOMAIN' => '',
            'API_ROUTE_PREFIX' => 'api',
            'ADMIN_API_ROUTE_PREFIX' => 'api/admin',
            'APP_CONFIG_CACHE' => $this->temporaryPath.'/config.php',
            'APP_ROUTES_CACHE' => $this->temporaryPath.'/routes.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'LOG_CHANNEL' => 'null',
        ], $environment)))->setTimeout(120);
    }
}
