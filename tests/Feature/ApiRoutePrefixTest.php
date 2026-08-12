<?php

namespace Tests\Feature;

use App\Support\ApiRouting;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApiRoutePrefixTest extends TestCase
{
    private const PROCESS_PROBE = 'ADMIN9_API_ROUTE_PREFIX_PROBE';

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
            $expectedAdminUri = $prefix === '' ? 'admin/auth/login' : 'api/admin/auth/login';
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

    private function assertBootedPrefixContract(string $expectedPrefix): void
    {
        $expectedRoot = $expectedPrefix === '' ? '' : '/'.$expectedPrefix;
        $expectedAdminPath = $expectedRoot.'/admin/auth/login';
        $expectedMemberPath = $expectedRoot.'/auth/login';

        $this->assertSame($expectedPrefix, ApiRouting::prefix());
        $this->assertSame($expectedAdminPath, ApiRouting::path('/admin/auth/login'));
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
            ...$environment,
            'API_ROUTE_PREFIX' => $prefix,
            'APP_CONFIG_CACHE' => base_path('bootstrap/cache/api-route-prefix-test.php'),
        ]);
    }
}
