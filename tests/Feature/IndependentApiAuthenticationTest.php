<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndependentApiAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        $environment = $this->providedData()[0];
        $cacheRoot = sys_get_temp_dir().'/admin9-independent-auth-'.bin2hex(random_bytes(8));
        $environment['APP_CONFIG_CACHE'] = $cacheRoot.'/config.php';
        $environment['APP_ROUTES_CACHE'] = $cacheRoot.'/routes.php';
        $original = [];

        foreach ($environment as $key => $value) {
            $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            parent::setUp();
        } finally {
            foreach ($original as $key => [$processValue, $environmentValue, $serverValue]) {
                putenv($processValue === false ? $key : $key.'='.$processValue);

                if ($environmentValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environmentValue;
                }

                if ($serverValue === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverValue;
                }
            }
        }
    }

    /** @param array<string, string> $environment */
    #[DataProvider('routingProvider')]
    public function test_authentication_is_isolated_with_independent_domains_and_prefixes(array $environment, string $memberBaseUrl, string $adminBaseUrl): void
    {
        $admin = User::factory()->create(['password' => 'password']);
        $member = Member::factory()->create(['id' => $admin->id, 'password' => 'password']);

        $adminToken = $this->postJson($adminBaseUrl.'/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->json('data.access_token');
        $memberToken = $this->postJson($memberBaseUrl.'/auth/login', [
            'account' => $member->email,
            'password' => 'password',
        ])->assertOk()->json('data.access_token');

        $this->assertIsString($adminToken);
        $this->assertIsString($memberToken);

        $this->getJson($adminBaseUrl.'/auth/me', ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()->assertJsonPath('data.user.id', $admin->id);
        $this->getJson($memberBaseUrl.'/auth/me', ['Authorization' => 'Bearer '.$memberToken])
            ->assertOk()->assertJsonPath('data.member.id', $member->id);

        foreach ([[$memberBaseUrl, $adminToken], [$adminBaseUrl, $memberToken]] as [$baseUrl, $foreignToken]) {
            $this->getJson($baseUrl.'/auth/me', ['Authorization' => 'Bearer '.$foreignToken])
                ->assertUnauthorized()->assertJsonPath('code', 401)->assertHeader('X-Request-Id');
            $this->postJson($baseUrl.'/auth/refresh', [], ['Authorization' => 'Bearer '.$foreignToken])
                ->assertUnauthorized()->assertJsonPath('code', 401)->assertHeader('X-Request-Id');
        }

        foreach ([[$adminBaseUrl, $adminToken], [$memberBaseUrl, $memberToken]] as [$baseUrl, $token]) {
            $this->getJson($baseUrl.'/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();
            $this->get($baseUrl.'/auth/me', ['Accept' => 'text/html'])
                ->assertUnauthorized()->assertJsonPath('code', 401)->assertHeader('X-Request-Id');
        }
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function routingProvider(): array
    {
        return [
            'unrelated prefixes' => [[
                'API_DOMAIN' => '', 'API_ROUTE_PREFIX' => 'v1',
                'ADMIN_API_DOMAIN' => '', 'ADMIN_API_ROUTE_PREFIX' => 'manage/v2',
            ], 'http://localhost/v1', 'http://localhost/manage/v2'],
            'separate hosts sharing a prefix' => [[
                'API_DOMAIN' => 'member.example.test', 'API_ROUTE_PREFIX' => 'v1',
                'ADMIN_API_DOMAIN' => 'admin.example.test', 'ADMIN_API_ROUTE_PREFIX' => 'v1',
            ], 'http://member.example.test/v1', 'http://admin.example.test/v1'],
            'only admin host is bound' => [[
                'API_DOMAIN' => '', 'API_ROUTE_PREFIX' => 'v1',
                'ADMIN_API_DOMAIN' => 'admin.example.test', 'ADMIN_API_ROUTE_PREFIX' => 'v1',
            ], 'http://member.example.test/v1', 'http://admin.example.test/v1'],
            'only member host is bound' => [[
                'API_DOMAIN' => 'member.example.test', 'API_ROUTE_PREFIX' => 'v1',
                'ADMIN_API_DOMAIN' => '', 'ADMIN_API_ROUTE_PREFIX' => 'v1',
            ], 'http://member.example.test/v1', 'http://admin.example.test/v1'],
            'member at host root' => [[
                'API_DOMAIN' => 'member.example.test', 'API_ROUTE_PREFIX' => '',
                'ADMIN_API_DOMAIN' => 'admin.example.test', 'ADMIN_API_ROUTE_PREFIX' => 'manage/v2',
            ], 'http://member.example.test', 'http://admin.example.test/manage/v2'],
            'admin at host root' => [[
                'API_DOMAIN' => 'member.example.test', 'API_ROUTE_PREFIX' => 'v1',
                'ADMIN_API_DOMAIN' => 'admin.example.test', 'ADMIN_API_ROUTE_PREFIX' => '',
            ], 'http://member.example.test/v1', 'http://admin.example.test'],
        ];
    }
}
