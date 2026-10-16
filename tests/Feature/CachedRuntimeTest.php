<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CachedRuntimeTest extends TestCase
{
    private string $runtimePath;

    /** @var array<string, string|false> */
    private array $runtimeEnvironment;

    private ?Process $worker = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimePath = sys_get_temp_dir().'/admin9-cached-runtime-'.bin2hex(random_bytes(8));
        $files = new Filesystem;

        foreach (['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'public'] as $directory) {
            $files->makeDirectory($this->runtimePath.'/'.$directory, 0755, true);
        }

        foreach (['artisan', 'bootstrap/app.php', 'bootstrap/providers.php', 'composer.json'] as $file) {
            $files->copy(base_path($file), $this->runtimePath.'/'.$file);
        }

        $files->copyDirectory(app_path(), $this->runtimePath.'/app');

        foreach (['config', 'database', 'resources', 'routes', 'vendor'] as $directory) {
            $files->link(base_path($directory), $this->runtimePath.'/'.$directory);
        }

        touch($this->runtimePath.'/runtime_test.sqlite');
        $this->runtimeEnvironment = [
            ...array_fill_keys(array_keys($_ENV + getenv()), false),
            'PATH' => (string) getenv('PATH'),
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'APP_URL' => 'http://runtime.example.test',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'LARAVEL_STORAGE_PATH' => $this->runtimePath.'/storage',
            'APP_CONFIG_CACHE' => $this->runtimePath.'/bootstrap/cache/config.php',
            'APP_ROUTES_CACHE' => $this->runtimePath.'/bootstrap/cache/routes.php',
            'APP_EVENTS_CACHE' => $this->runtimePath.'/bootstrap/cache/events.php',
            'APP_SERVICES_CACHE' => $this->runtimePath.'/bootstrap/cache/services.php',
            'APP_PACKAGES_CACHE' => $this->runtimePath.'/bootstrap/cache/packages.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->runtimePath.'/runtime_test.sqlite',
            'DB_URL' => '',
            'DB_FOREIGN_KEYS' => 'true',
            'DB_QUEUE_CONNECTION' => 'sqlite',
            'DB_CACHE_CONNECTION' => 'sqlite',
            'DB_CACHE_LOCK_CONNECTION' => 'sqlite',
            'CACHE_STORE' => 'database',
            'CACHE_PREFIX' => 'runtime-',
            'QUEUE_CONNECTION' => 'database',
            'QUEUE_MONITOR_QUEUES' => 'database:default',
            'SESSION_DRIVER' => 'array',
            'FILESYSTEM_DISK' => 'local',
            'LOG_CHANNEL' => 'single',
            'LOG_STACK' => 'single',
            'ENABLE_QUERY_LOG' => 'false',
            'MAIL_MAILER' => 'array',
            'JWT_SECRET' => 'cached-runtime-test-only-jwt-secret',
            'BCRYPT_ROUNDS' => '4',
        ];

        $this->artisanProcess(['migrate', '--force']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->worker?->isRunning()) {
                $this->worker->stop(1);
            }

            if (isset($this->runtimePath)) {
                (new Filesystem)->deleteDirectory($this->runtimePath);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @param array<string, string> $routing */
    #[DataProvider('routingProvider')]
    public function test_cached_routes_events_and_api_contracts_match_uncached_runtime(array $routing, string $clientBaseUrl, string $adminBaseUrl): void
    {
        $this->runtimeEnvironment = [...$this->runtimeEnvironment, ...$routing];
        $clientPath = parse_url($clientBaseUrl, PHP_URL_PATH) ?: '';
        $adminPath = parse_url($adminBaseUrl, PHP_URL_PATH) ?: '';
        $clientOrigin = 'http://'.parse_url($clientBaseUrl, PHP_URL_HOST);
        $adminOrigin = 'http://'.parse_url($adminBaseUrl, PHP_URL_HOST);
        $hostRequests = [];

        if ($routing['API_DOMAIN'] !== '' && $routing['ADMIN_API_DOMAIN'] !== '') {
            foreach ([$clientPath, $adminPath] as $path) {
                $hostRequests[] = ['GET', 'http://unrelated.example.test'.$path.'/auth/me', 404, null];
                $hostRequests[] = ['POST', 'http://unrelated.example.test'.$path.'/auth/login', 404, null];
            }

            $hostRequests[] = ['DELETE', $adminOrigin.$clientPath.'/auth/sessions', 404, null];
            $hostRequests[] = ['GET', $clientOrigin.$adminPath.'/users', 404, null];
        } elseif ($routing['ADMIN_API_DOMAIN'] !== '') {
            $hostRequests[] = ['DELETE', $adminOrigin.$clientPath.'/auth/sessions', 401, 'member.auth.sessions.destroy'];
        } elseif ($routing['API_DOMAIN'] !== '') {
            $hostRequests[] = ['GET', $clientOrigin.$adminPath.'/users', 401, 'admin.users.index'];
        }

        $routes = $this->routeContract();
        $events = $this->artisanProcess(['event:list', '--json'])->getOutput();
        $this->assertStringContainsString('LogQueueBusy@handle', $events);
        $before = $this->httpContract($clientBaseUrl, $adminBaseUrl, $hostRequests);

        $this->artisanProcess(['config:cache']);
        $this->assertFileExists($this->runtimeEnvironment['APP_CONFIG_CACHE']);
        $this->assertFileDoesNotExist($this->runtimeEnvironment['APP_ROUTES_CACHE']);

        $configuration = require $this->runtimeEnvironment['APP_CONFIG_CACHE'];
        $this->assertFileDoesNotExist($this->runtimePath.'/.env');
        $this->assertSame($this->runtimePath.'/runtime_test.sqlite', $configuration['database']['connections']['sqlite']['database']);
        $this->assertSame($this->runtimePath.'/storage/app/public', $configuration['filesystems']['disks']['public']['root']);
        $this->assertSame($this->runtimePath.'/storage/logs/laravel.log', $configuration['logging']['channels']['single']['path']);
        $this->assertSame('cached-runtime-test-only-jwt-secret', $configuration['jwt']['secret']);
        $this->assertNull($configuration['services']['ses']['secret']);
        $this->assertNull($configuration['filesystems']['disks']['s3']['secret']);
        $this->assertNull($configuration['mail']['mailers']['smtp']['password']);
        $this->runtimeEnvironment['API_ROUTE_PREFIX'] = 'must-not-override-cached-prefix';
        $this->runtimeEnvironment['ADMIN_API_ROUTE_PREFIX'] = 'must-not-override-cached-admin-prefix';
        $this->runtimeEnvironment['API_DOMAIN'] = 'must-not-override-client.example.test';
        $this->runtimeEnvironment['ADMIN_API_DOMAIN'] = 'must-not-override-admin.example.test';

        $this->assertSame($routes, $this->routeContract());
        $this->assertSame($events, $this->artisanProcess(['event:list', '--json'])->getOutput());
        $this->assertSame($before, $this->httpContract($clientBaseUrl, $adminBaseUrl, $hostRequests));

        foreach (['route:cache', 'event:cache'] as $command) {
            $this->artisanProcess([$command]);
        }

        foreach (['APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            $this->assertFileExists($this->runtimeEnvironment[$key]);
        }

        $this->assertSame($routes, $this->routeContract());
        $this->assertSame($events, $this->artisanProcess(['event:list', '--json'])->getOutput());
        $after = $this->httpContract($clientBaseUrl, $adminBaseUrl, $hostRequests);
        $this->assertSame($before, $after);
        $this->assertSame($routing['API_ROUTE_PREFIX'], $after['prefix']);
        $this->assertSame($routing['ADMIN_API_ROUTE_PREFIX'], $after['admin_prefix']);
        $this->assertSame($routing['API_DOMAIN'] ?: null, $after['domain']);
        $this->assertSame($routing['ADMIN_API_DOMAIN'] ?: null, $after['admin_domain']);
        $this->assertSame((parse_url($clientBaseUrl, PHP_URL_PATH) ?: '').'/auth/login', $after['client_login_path']);
        $this->assertSame((parse_url($adminBaseUrl, PHP_URL_PATH) ?: '').'/auth/login', $after['admin_login_path']);
        $this->assertSame([422, 422, 401, 401, 404, 404, 405, 405], array_column($after['responses'], 'status'));
        $this->assertSame(['admin.auth.login', 'member.auth.login', 'admin.auth.me', 'member.auth.me', null, null, null, null], array_column($after['responses'], 'route_name'));
        $this->assertSame('App\\Http\\Controllers\\Api\\Admin\\AuthController@login', $after['responses'][0]['route_action']);
        $this->assertSame('App\\Http\\Controllers\\Api\\AuthController@login', $after['responses'][1]['route_action']);
        $this->assertContains('throttle:admin-login', $after['responses'][0]['route_middleware']);
        $this->assertContains('throttle:member-login', $after['responses'][1]['route_middleware']);
        $this->assertContains('auth:admin', $after['responses'][2]['route_middleware']);
        $this->assertContains('auth:member', $after['responses'][3]['route_middleware']);
        $this->assertContains('email', $after['responses'][0]['error_fields']);
        $this->assertContains('account', $after['responses'][1]['error_fields']);
        $this->assertSame(array_map(static fn (array $request): array => [
            'status' => $request[2],
            'route_name' => $request[3],
        ], $hostRequests), $after['host_responses']);

        foreach ($after['responses'] as $response) {
            $this->assertSame($response['status'], $response['code']);
            $this->assertFalse($response['success']);
            $this->assertTrue($response['request_id_matches_header']);
            $this->assertTrue($response['data_is_object']);
            $this->assertTrue($response['errors_is_object']);
            $this->assertTrue($response['is_json']);
            $this->assertSame('*', $response['cors_origin']);

            if ($response['status'] === 405) {
                $this->assertStringContainsString('POST', $response['allow']);
            }
        }

        $this->assertSame([
            ['status' => 204, 'origin' => '*'],
            ['status' => 204, 'origin' => '*'],
        ], $after['preflight']);
        $this->assertTrue($after['queue_busy_logged']);
    }

    public function test_cached_database_queue_worker_exits_cleanly_after_restart_signal(): void
    {
        $this->artisanProcess(['config:cache']);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['events']->listen(Illuminate\Queue\Events\WorkerStarting::class, fn () => fwrite(STDOUT, "runtime-worker-ready\n"));
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
PHP;
        $this->worker = $this->process([PHP_BINARY, '-r', $script, '--', 'queue:work', 'database', '--sleep=1', '--timeout=5', '--no-interaction', '--no-ansi']);
        $this->worker->setTimeout(15);
        $this->worker->start();

        $this->assertTrue($this->worker->waitUntil(
            fn (string $type, string $output): bool => str_contains($output, 'runtime-worker-ready'),
        ), $this->worker->getOutput().$this->worker->getErrorOutput());
        $this->assertTrue($this->worker->isRunning());

        $this->artisanProcess(['queue:restart']);
        $this->worker->wait();

        $this->assertSame(0, $this->worker->getExitCode(), $this->worker->getOutput().$this->worker->getErrorOutput());
        $this->assertStringNotContainsString('ERROR', $this->worker->getOutput().$this->worker->getErrorOutput());
        $database = new PDO('sqlite:'.$this->runtimePath.'/runtime_test.sqlite');
        $this->assertSame(1, (int) $database->query("SELECT COUNT(*) FROM cache WHERE key = 'runtime-illuminate:queue:restart'")->fetchColumn());
        $this->assertSame(0, (int) $database->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function routingProvider(): array
    {
        return [
            'default' => [
                ['API_ROUTE_PREFIX' => 'api', 'API_DOMAIN' => '', 'ADMIN_API_ROUTE_PREFIX' => 'api/admin', 'ADMIN_API_DOMAIN' => ''],
                'http://runtime.example.test/api',
                'http://runtime.example.test/api/admin',
            ],
            'unrelated prefixes' => [
                ['API_ROUTE_PREFIX' => 'v1', 'API_DOMAIN' => '', 'ADMIN_API_ROUTE_PREFIX' => 'manage/v2', 'ADMIN_API_DOMAIN' => ''],
                'http://runtime.example.test/v1',
                'http://runtime.example.test/manage/v2',
            ],
            'separate domains with identical paths' => [
                ['API_ROUTE_PREFIX' => 'api', 'API_DOMAIN' => 'client.example.test', 'ADMIN_API_ROUTE_PREFIX' => 'api', 'ADMIN_API_DOMAIN' => 'admin.example.test'],
                'http://client.example.test/api',
                'http://admin.example.test/api',
            ],
            'admin root' => [
                ['API_ROUTE_PREFIX' => 'v1', 'API_DOMAIN' => 'client.example.test', 'ADMIN_API_ROUTE_PREFIX' => '', 'ADMIN_API_DOMAIN' => 'admin.example.test'],
                'http://client.example.test/v1',
                'http://admin.example.test',
            ],
            'client root' => [
                ['API_ROUTE_PREFIX' => '', 'API_DOMAIN' => 'client.example.test', 'ADMIN_API_ROUTE_PREFIX' => 'manage/v2', 'ADMIN_API_DOMAIN' => 'admin.example.test'],
                'http://client.example.test',
                'http://admin.example.test/manage/v2',
            ],
            'only admin domain bound' => [
                ['API_ROUTE_PREFIX' => 'api', 'API_DOMAIN' => '', 'ADMIN_API_ROUTE_PREFIX' => 'api', 'ADMIN_API_DOMAIN' => 'admin.example.test'],
                'http://runtime.example.test/api',
                'http://admin.example.test/api',
            ],
            'only client domain bound' => [
                ['API_ROUTE_PREFIX' => 'api', 'API_DOMAIN' => 'client.example.test', 'ADMIN_API_ROUTE_PREFIX' => 'api', 'ADMIN_API_DOMAIN' => ''],
                'http://client.example.test/api',
                'http://runtime.example.test/api',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function routeContract(): array
    {
        $routes = json_decode($this->artisanProcess(['route:list', '--json'])->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($routes as &$route) {
            unset($route['path']);

            if (str_starts_with($route['name'] ?? '', 'generated::')) {
                $route['name'] = null;
            }
        }

        return $routes;
    }

    /**
     * @param  array<int, array{string, string, int, ?string}>  $hostRequests
     * @return array<string, mixed>
     */
    private function httpContract(string $clientBaseUrl, string $adminBaseUrl, array $hostRequests): array
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('/'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
[$clientBaseUrl, $adminBaseUrl, $hostRequests] = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$result = [
    'prefix' => App\Support\ApiRouting::prefix(),
    'admin_prefix' => App\Support\ApiRouting::adminPrefix(),
    'domain' => App\Support\ApiRouting::domain(),
    'admin_domain' => App\Support\ApiRouting::adminDomain(),
    'client_login_path' => route('member.auth.login', absolute: false),
    'admin_login_path' => route('admin.auth.login', absolute: false),
    'responses' => [],
    'host_responses' => [],
    'preflight' => [],
];
foreach ([
    ['POST', $adminBaseUrl.'/auth/login'],
    ['POST', $clientBaseUrl.'/auth/login'],
    ['GET', $adminBaseUrl.'/auth/me'],
    ['GET', $clientBaseUrl.'/auth/me'],
    ['GET', $adminBaseUrl.'/_runtime_missing'],
    ['GET', $clientBaseUrl.'/_runtime_missing'],
    ['PUT', $adminBaseUrl.'/auth/login'],
    ['PUT', $clientBaseUrl.'/auth/login'],
] as [$method, $url]) {
    $request = Illuminate\Http\Request::create($url, $method, server: [
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_ORIGIN' => 'https://console.example.test',
    ]);
    $response = $kernel->handle($request);
    $payload = json_decode($response->getContent());
    $result['responses'][] = [
        'status' => $response->getStatusCode(),
        'code' => $payload->code ?? null,
        'success' => $payload->success ?? null,
        'request_id_matches_header' => is_string($payload->request_id ?? null) && $payload->request_id === $response->headers->get('X-Request-Id'),
        'data_is_object' => ($payload->data ?? null) instanceof stdClass,
        'errors_is_object' => ($payload->errors ?? null) instanceof stdClass,
        'error_fields' => array_keys((array) ($payload->errors ?? null)),
        'is_json' => str_contains((string) $response->headers->get('Content-Type'), 'application/json'),
        'cors_origin' => $response->headers->get('Access-Control-Allow-Origin'),
        'allow' => (string) $response->headers->get('Allow'),
        'route_name' => $request->route()?->getName(),
        'route_action' => $request->route()?->getActionName(),
        'route_middleware' => $request->route()?->gatherMiddleware() ?? [],
    ];
    $kernel->terminate($request, $response);
}
foreach ($hostRequests as [$method, $url]) {
    $request = Illuminate\Http\Request::create($url, $method, server: ['HTTP_ACCEPT' => 'text/html']);
    $response = $kernel->handle($request);
    $result['host_responses'][] = [
        'status' => $response->getStatusCode(),
        'route_name' => $request->route()?->getName(),
    ];
    $kernel->terminate($request, $response);
}
foreach ([$clientBaseUrl, $adminBaseUrl] as $baseUrl) {
    $request = Illuminate\Http\Request::create($baseUrl.'/auth/login', 'OPTIONS', server: [
        'HTTP_ORIGIN' => 'https://console.example.test',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
    ]);
    $response = $kernel->handle($request);
    $result['preflight'][] = [
        'status' => $response->getStatusCode(),
        'origin' => $response->headers->get('Access-Control-Allow-Origin'),
    ];
    $kernel->terminate($request, $response);
}
$logPath = storage_path('logs/laravel.log');
$previousLogSize = is_file($logPath) ? filesize($logPath) : 0;
Illuminate\Support\Facades\Event::dispatch(new Illuminate\Queue\Events\QueueBusy('database', 'runtime-probe', 1001));
clearstatcache(true, $logPath);
$result['queue_busy_logged'] = is_file($logPath) && filesize($logPath) > $previousLogSize
    && str_contains(file_get_contents($logPath, offset: $previousLogSize), 'Queue backlog threshold reached');
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $process = $this->process([PHP_BINARY, '-r', $script, json_encode([$clientBaseUrl, $adminBaseUrl, $hostRequests], JSON_THROW_ON_ERROR)]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, string> $arguments */
    private function artisanProcess(array $arguments): Process
    {
        $process = $this->process([PHP_BINARY, 'artisan', ...$arguments, '--no-interaction', '--no-ansi']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return $process;
    }

    /** @param array<int, string> $command */
    private function process(array $command): Process
    {
        return new Process($command, $this->runtimePath, $this->runtimeEnvironment, timeout: 30);
    }
}
