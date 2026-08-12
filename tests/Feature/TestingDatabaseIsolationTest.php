<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestingDatabaseIsolationTest extends TestCase
{
    private const PHPUNIT_ENVIRONMENT_PROBE = 'ADMIN9_PHPUNIT_ENVIRONMENT_PROBE';

    public function test_artisan_accepts_an_isolated_in_memory_database_in_the_testing_environment(): void
    {
        $process = $this->artisanProcess([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ]);

        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_artisan_loads_the_isolated_testing_environment_file_by_default(): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'about', '--env=testing'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => base_path('bootstrap/cache/testing-database-guard.php'),
                'APP_ENV' => false,
                'DB_CONNECTION' => false,
                'DB_DATABASE' => false,
                'DB_URL' => false,
            ],
        );

        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('Environment', $process->getOutput());
        $this->assertStringContainsString('testing', $process->getOutput());
    }

    public function test_artisan_refuses_a_non_test_database_in_the_testing_environment(): void
    {
        $process = $this->artisanProcess([
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'admin9_api_laravel',
            'DB_URL' => '',
        ]);

        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'Refusingtobootthetestingenvironmentwithunsafedatabase[mysql:admin9_api_laravel]',
            $this->withoutWhitespace($process),
        );
    }

    public function test_artisan_refuses_an_unsafe_database_url_override(): void
    {
        $process = $this->artisanProcess([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => 'mysql://root:secret@127.0.0.1/admin9_api_laravel',
        ]);

        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'Refusingtobootthetestingenvironmentwithunsafedatabase[mysql:admin9_api_laravel]',
            $this->withoutWhitespace($process),
        );
    }

    public function test_phpunit_forces_isolated_database_configuration_over_parent_environment(): void
    {
        if (getenv(self::PHPUNIT_ENVIRONMENT_PROBE) === '1') {
            $this->assertTrue(app()->environment('testing'), json_encode([
                'app_environment' => app()->environment(),
                'getenv_app_environment' => getenv('APP_ENV'),
                'server_app_environment' => $_SERVER['APP_ENV'] ?? null,
                'env_app_environment' => $_ENV['APP_ENV'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
            $this->assertEmpty(config('database.connections.sqlite.url'));

            return;
        }

        $process = new Process(
            [
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--configuration=phpunit.xml',
                '--filter=test_phpunit_forces_isolated_database_configuration_over_parent_environment',
                __FILE__,
            ],
            base_path(),
            [
                self::PHPUNIT_ENVIRONMENT_PROBE => '1',
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'admin9_api_laravel',
                'DB_URL' => 'mysql://root:secret@127.0.0.1/admin9_api_laravel',
            ],
        );

        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function artisanProcess(array $environment): Process
    {
        return new Process(
            [PHP_BINARY, 'artisan', 'about', '--env=testing'],
            base_path(),
            [
                ...$environment,
                'APP_CONFIG_CACHE' => base_path('bootstrap/cache/testing-database-guard.php'),
                'APP_ENV' => 'testing',
            ],
        );
    }

    private function withoutWhitespace(Process $process): string
    {
        return preg_replace('/\s+/', '', $process->getOutput().$process->getErrorOutput()) ?? '';
    }
}
