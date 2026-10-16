<?php

namespace Tests\Feature;

use App\Support\ApiRouting;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApiRoutePrefixTest extends TestCase
{
    private const PROCESS_PROBE = 'ADMIN9_API_ROUTE_PREFIX_PROBE';

    protected function setUp(): void
    {
        $prefix = getenv(self::PROCESS_PROBE);

        if ($prefix === false) {
            parent::setUp();

            return;
        }

        $original = [getenv('API_ROUTE_PREFIX'), $_ENV['API_ROUTE_PREFIX'] ?? null, $_SERVER['API_ROUTE_PREFIX'] ?? null];
        putenv('API_ROUTE_PREFIX='.$prefix);
        $_ENV['API_ROUTE_PREFIX'] = $prefix;
        $_SERVER['API_ROUTE_PREFIX'] = $prefix;

        try {
            parent::setUp();
        } finally {
            putenv($original[0] === false ? 'API_ROUTE_PREFIX' : 'API_ROUTE_PREFIX='.$original[0]);

            foreach (['_ENV' => $original[1], '_SERVER' => $original[2]] as $global => $value) {
                if ($value === null) {
                    unset($GLOBALS[$global]['API_ROUTE_PREFIX']);
                } else {
                    $GLOBALS[$global]['API_ROUTE_PREFIX'] = $value;
                }
            }
        }
    }

    public function test_api_route_prefix_modes_boot_independently(): void
    {
        if (($expectedPrefix = getenv(self::PROCESS_PROBE)) !== false) {
            $this->assertBootedPrefixContract($expectedPrefix);

            return;
        }

        foreach (['api', ''] as $prefix) {
            $routeList = $this->process(
                [PHP_BINARY, 'artisan', 'route:list', '--except-vendor', '--json'],
                $prefix,
            );
            $routeList->run();

            $this->assertTrue($routeList->isSuccessful(), $routeList->getOutput().$routeList->getErrorOutput());
            $routes = collect(json_decode($routeList->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            $expectedAdminUri = 'api/admin/auth/login';
            $expectedMemberUri = $prefix === '' ? 'auth/login' : 'api/auth/login';
            $this->assertSame($expectedAdminUri, $routes->firstWhere('name', 'admin.auth.login')['uri'] ?? null);
            $this->assertSame($expectedMemberUri, $routes->firstWhere('name', 'member.auth.login')['uri'] ?? null);

            $phpunit = $this->process([
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--configuration=phpunit.xml',
                '--filter=test_api_route_prefix_modes_boot_independently',
                __FILE__,
            ], $prefix, [self::PROCESS_PROBE => $prefix]);
            $phpunit->run();

            $this->assertTrue($phpunit->isSuccessful(), $phpunit->getOutput().$phpunit->getErrorOutput());
        }
    }

    #[DataProvider('prefixNormalizationProvider')]
    public function test_api_route_prefix_is_normalized_in_one_place(string $configured, string $expected): void
    {
        config()->set('app.api_route_prefix', $configured);

        $this->assertSame($expected, ApiRouting::prefix());
        $this->assertSame(($expected === '' ? '' : '/'.$expected).'/auth/login', ApiRouting::path('/auth/login'));
    }

    public static function prefixNormalizationProvider(): array
    {
        return [
            'plain' => ['api', 'api'],
            'leading slash' => ['/api', 'api'],
            'trailing slash' => ['api/', 'api'],
            'surrounding slashes' => ['/api/', 'api'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('domainNormalizationProvider')]
    public function test_domains_are_normalized(string $configured, ?string $expected): void
    {
        config()->set('app.api_domain', $configured);
        config()->set('app.admin_api_domain', $configured);

        $this->assertSame($expected, ApiRouting::domain());
        $this->assertSame($expected, ApiRouting::adminDomain());
    }

    public static function domainNormalizationProvider(): array
    {
        return [
            'empty' => ['', null],
            'case and whitespace' => [' ADMIN.EXAMPLE.TEST ', 'admin.example.test'],
            'absolute hostname' => ['admin.example.test.', 'admin.example.test'],
            'localhost' => ['localhost', 'localhost'],
        ];
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_invalid_domains_and_prefixes_are_rejected(string $key, string $value, string $method): void
    {
        config()->set($key, $value);

        $this->expectException(InvalidArgumentException::class);

        ApiRouting::{$method}();
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            ['app.api_domain', 'https://api.example.test', 'domain'],
            ['app.admin_api_domain', 'api.example.test:8080', 'adminDomain'],
            ['app.api_domain', '{tenant}.example.test', 'domain'],
            ['app.admin_api_domain', 'api.example.test/path', 'adminDomain'],
            ['app.api_route_prefix', 'api/{version}', 'prefix'],
            ['app.admin_api_route_prefix', 'api/*', 'adminPrefix'],
            ['app.api_route_prefix', 'api/../admin', 'prefix'],
            ['app.admin_api_route_prefix', 'api//admin', 'adminPrefix'],
        ];
    }

    public function test_root_prefix_is_scoped_to_its_domain_for_api_errors_and_cors(): void
    {
        config()->set([
            'app.api_domain' => null,
            'app.api_route_prefix' => 'api',
            'app.admin_api_domain' => 'admin.example.test',
            'app.admin_api_route_prefix' => '',
            'cors.paths' => [],
        ]);
        config()->set('cors.paths', ApiRouting::corsPaths());

        $this->assertTrue(ApiRouting::matches(Request::create('https://admin.example.test/anything')));
        $this->assertTrue(ApiRouting::matches(Request::create('https://other.example.test/api/auth/login')));
        $this->assertFalse(ApiRouting::matches(Request::create('https://other.example.test/anything')));
        $this->assertFalse(ApiRouting::matches(Request::create('https://other.example.test/apiary')));

        foreach ([
            ['https://admin.example.test/anything', true],
            ['https://other.example.test/api/auth/login', true],
            ['https://other.example.test/anything', false],
        ] as [$url, $expectedCors]) {
            $response = $this->call('OPTIONS', $url, server: [
                'HTTP_ORIGIN' => 'https://console.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);

            $this->assertSame($expectedCors, $response->headers->has('Access-Control-Allow-Origin'));
        }
    }

    public function test_host_specific_cors_paths_include_the_unbound_surface(): void
    {
        config()->set([
            'app.api_domain' => null,
            'app.api_route_prefix' => 'client',
            'app.admin_api_domain' => 'admin.example.test',
            'app.admin_api_route_prefix' => 'manage',
        ]);
        config()->set('cors.paths', ApiRouting::corsPaths());

        foreach (['client/auth/login', 'manage/auth/login'] as $path) {
            $this->call('OPTIONS', 'https://admin.example.test/'.$path, server: [
                'HTTP_ORIGIN' => 'https://console.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        }

        $this->assertFalse(ApiRouting::matches(Request::create('https://other.example.test/manage/auth/login')));
    }

    #[DataProvider('independentRoutingProvider')]
    public function test_independent_routes_match_identically_with_and_without_compilation(
        ?string $clientDomain,
        string $clientPrefix,
        ?string $adminDomain,
        string $adminPrefix,
        string $host,
        string $path,
        string $expectedName,
    ): void {
        $router = $this->configuredRouter($clientDomain, $clientPrefix, $adminDomain, $adminPrefix);
        $routes = $router->getRoutes();
        $compiled = $routes->toCompiledRouteCollection($router, $this->app);

        foreach ([$routes, $compiled] as $collection) {
            $matched = $collection->match(Request::create('https://'.$host.'/'.$path, 'POST'));
            $this->assertSame($expectedName, $matched->getName());
        }
    }

    public static function independentRoutingProvider(): array
    {
        return [
            'default' => [null, 'api', null, 'api/admin', 'other.test', 'api/admin/auth/login', 'admin.auth.login'],
            'admin only domain root' => [null, '', 'admin.test', '', 'admin.test', 'auth/login', 'admin.auth.login'],
            'admin domain member fallback elsewhere' => [null, '', 'admin.test', '', 'other.test', 'auth/login', 'member.auth.login'],
            'client only domain root' => ['client.test', '', null, '', 'client.test', 'auth/login', 'member.auth.login'],
            'client domain admin fallback elsewhere' => ['client.test', '', null, '', 'other.test', 'auth/login', 'admin.auth.login'],
            'two domains root' => ['client.test', '', 'admin.test', '', 'admin.test', 'auth/login', 'admin.auth.login'],
            'independent prefixes' => [null, 'client/v2', null, 'management', 'other.test', 'management/auth/login', 'admin.auth.login'],
        ];
    }

    #[DataProvider('conflictingPrefixProvider')]
    public function test_same_domain_route_conflicts_are_rejected(string $clientPrefix, string $adminPrefix): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('API route conflict:');

        $this->configuredRouter(null, $clientPrefix, null, $adminPrefix);
    }

    public static function conflictingPrefixProvider(): array
    {
        return [
            'identical' => ['api', 'api'],
            'root' => ['', ''],
            'nested user password' => ['api/users', 'api'],
            'nested member password' => ['api/members', 'api'],
        ];
    }

    #[DataProvider('bootstrapConflictProvider')]
    public function test_bootstrap_rejects_route_conflicts_before_listing_or_caching(
        string $command,
        string $clientPrefix,
        string $clientRoute,
        string $adminRoute,
    ): void {
        $cacheDirectory = sys_get_temp_dir().'/admin9-route-conflict-'.bin2hex(random_bytes(8));
        mkdir($cacheDirectory, 0700);
        $configCache = $cacheDirectory.'/config.php';
        $routeCache = $cacheDirectory.'/routes.php';

        try {
            $process = $this->process([PHP_BINARY, 'artisan', $command, '--no-interaction'], $clientPrefix, [
                'API_DOMAIN' => 'shared.example.test',
                'ADMIN_API_DOMAIN' => 'shared.example.test',
                'ADMIN_API_ROUTE_PREFIX' => 'api',
                'APP_CONFIG_CACHE' => $configCache,
                'APP_ROUTES_CACHE' => $routeCache,
            ]);
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertFalse($process->isSuccessful(), $output);
            $this->assertStringContainsString('API route conflict:', $output);
            $unwrappedOutput = preg_replace('/\s+/', '', $output);
            $this->assertStringContainsString($clientRoute, $unwrappedOutput);
            $this->assertStringContainsString($adminRoute, $unwrappedOutput);
            $this->assertFileDoesNotExist($routeCache);
        } finally {
            foreach ([$configCache, $routeCache] as $cachePath) {
                if (is_file($cachePath)) {
                    unlink($cachePath);
                }
            }

            rmdir($cacheDirectory);
        }
    }

    public static function bootstrapConflictProvider(): array
    {
        return [
            'list identical prefixes' => ['route:list', 'api', 'member.auth.login', 'admin.auth.login'],
            'cache identical prefixes' => ['route:cache', 'api', 'member.auth.login', 'admin.auth.login'],
            'list nested prefixes' => ['route:list', 'api/users', 'member.auth.password.update', 'admin.users.password.update'],
            'cache nested prefixes' => ['route:cache', 'api/users', 'member.auth.password.update', 'admin.users.password.update'],
        ];
    }

    private function configuredRouter(?string $clientDomain, string $clientPrefix, ?string $adminDomain, string $adminPrefix): Router
    {
        config()->set([
            'app.api_domain' => $clientDomain,
            'app.api_route_prefix' => $clientPrefix,
            'app.admin_api_domain' => $adminDomain,
            'app.admin_api_route_prefix' => $adminPrefix,
        ]);
        $originalRouter = Route::getFacadeRoot();
        $router = new Router($this->app['events'], $this->app);
        Route::swap($router);

        try {
            $router->middleware('api')->domain(ApiRouting::domain())->prefix(ApiRouting::prefix())
                ->group(base_path('routes/api.php'));
            $clientRoutes = $router->getRoutes()->getRoutes();
            $router->middleware('api')->domain(ApiRouting::adminDomain())->prefix(ApiRouting::adminPrefix())
                ->group(base_path('routes/admin.php'));
            $adminRoutes = array_filter($router->getRoutes()->getRoutes(),
                static fn (\Illuminate\Routing\Route $route): bool => str_starts_with((string) $route->getName(), 'admin.'));
            ApiRouting::assertNoRouteConflicts($clientRoutes, $adminRoutes);
        } finally {
            Route::swap($originalRouter);
        }

        return $router;
    }

    private function assertBootedPrefixContract(string $expectedPrefix): void
    {
        $expectedRoot = $expectedPrefix === '' ? '' : '/'.$expectedPrefix;
        $expectedAdminPath = '/api/admin/auth/login';
        $expectedMemberPath = $expectedRoot.'/auth/login';

        $this->assertSame($expectedPrefix, ApiRouting::prefix());
        $this->assertSame($expectedAdminPath, ApiRouting::adminPath('/auth/login'));
        $this->assertSame($expectedAdminPath, route('admin.auth.login', absolute: false));
        $this->assertSame($expectedMemberPath, route('member.auth.login', absolute: false));
        $this->assertTrue(ApiRouting::matches(Request::create($expectedAdminPath)));
        $this->assertSame($expectedPrefix === '', ApiRouting::matches(Request::create('/')));

        $this->postJson($expectedAdminPath)
            ->assertUnprocessable()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('code', 422);
        $this->postJson($expectedMemberPath)
            ->assertUnprocessable()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('code', 422);

        $this->getJson($expectedRoot.'/_test/missing-route')
            ->assertNotFound()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('code', 404)
            ->assertJsonPath('request_id', fn (mixed $requestId): bool => is_string($requestId));

        $this->call('OPTIONS', $expectedMemberPath, server: [
            'HTTP_ORIGIN' => 'https://console.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function process(array $command, string $prefix, array $environment = []): Process
    {
        return new Process($command, base_path(), [
            'API_ROUTE_PREFIX' => $prefix,
            'API_DOMAIN' => '',
            'ADMIN_API_ROUTE_PREFIX' => 'api/admin',
            'ADMIN_API_DOMAIN' => '',
            'APP_CONFIG_CACHE' => base_path('bootstrap/cache/api-route-prefix-test.php'),
            'APP_ROUTES_CACHE' => base_path('bootstrap/cache/api-route-prefix-routes-test.php'),
            ...$environment,
        ]);
    }
}
