<?php

namespace App\Support\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;

final class TestingDatabaseGuard
{
    public static function ensureIsolated(Application $app): void
    {
        if (! $app->environment('testing')) {
            return;
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
}
