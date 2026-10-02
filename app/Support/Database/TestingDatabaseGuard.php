<?php

namespace App\Support\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;

final class TestingDatabaseGuard
{
    public static function ensureIsolated(Application $app): void
    {
        if ($app->runningInConsole() && ($_SERVER['argv'][1] ?? null) === 'config:clear') {
            return;
        }

        if (! self::testingWasRequested($app)) {
            return;
        }

        if ($app->configurationIsCached() && config('app.env') !== 'testing') {
            throw new RuntimeException('Refusing to boot the testing environment with cached non-testing configuration. Clear the selected configuration cache before testing.');
        }

        $connectionName = (string) config('database.default');
        $configuredConnection = config("database.connections.{$connectionName}");

        if (! is_array($configuredConnection)) {
            throw new RuntimeException("Refusing to boot the testing environment with unknown database connection [{$connectionName}].");
        }

        $connection = (new ConfigurationUrlParser)->parseConfiguration($configuredConnection);
        $driver = (string) ($connection['driver'] ?? $connectionName);
        $database = (string) ($connection['database'] ?? '');

        if (self::isTestDatabase($driver, $database)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to boot the testing environment with unsafe database [%s:%s]. Use SQLite :memory: or a database name containing a test segment.',
            $driver,
            $database,
        ));
    }

    private static function isTestDatabase(string $driver, string $database): bool
    {
        if ($driver === 'sqlite' && $database === ':memory:') {
            return true;
        }

        $databaseName = pathinfo($database, PATHINFO_FILENAME);

        return preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $databaseName) === 1;
    }

    private static function testingWasRequested(Application $app): bool
    {
        if ($app->environment('testing') || defined('PHPUNIT_COMPOSER_INSTALL')) {
            return true;
        }

        if ($app->runningInConsole()
            && ($_SERVER['argv'][1] ?? null) === 'test'
            && $app->configurationIsCached()) {
            return true;
        }

        return in_array('testing', [
            $_SERVER['APP_ENV'] ?? null,
            $_ENV['APP_ENV'] ?? null,
            getenv('APP_ENV'),
        ], true);
    }
}
