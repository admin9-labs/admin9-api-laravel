<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class MySqlConcurrencyDatabaseGuard
{
    /**
     * @return array{database: string, version: string, version_comment: string}
     */
    public static function assertSafe(): array
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('MySQL concurrency tests require APP_ENV=testing.');
        }

        if (DB::getDefaultConnection() !== 'mysql') {
            throw new RuntimeException('MySQL concurrency tests require DB_CONNECTION=mysql.');
        }

        $expectedDatabase = self::environmentValue('MYSQL_CONCURRENCY_DATABASE');

        if ($expectedDatabase === null) {
            throw new RuntimeException('MYSQL_CONCURRENCY_DATABASE must explicitly name the disposable test database.');
        }

        if (! preg_match('/\A[a-zA-Z0-9]+(?:_[a-zA-Z0-9]+)*_test(?:_[a-zA-Z0-9]+)*\z/', $expectedDatabase)) {
            throw new RuntimeException('MYSQL_CONCURRENCY_DATABASE must contain an explicit _test database segment.');
        }

        $connection = DB::connection();
        $configuredDatabase = (string) $connection->getDatabaseName();

        if (! hash_equals($expectedDatabase, $configuredDatabase)) {
            throw new RuntimeException('MYSQL_CONCURRENCY_DATABASE must exactly match the configured MySQL database.');
        }

        if ($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('The concurrency database must use the PDO MySQL driver.');
        }

        $metadata = $connection->selectOne(
            'select database() as database_name, @@version as version, @@version_comment as version_comment'
        );
        $actualDatabase = (string) ($metadata->database_name ?? '');
        $version = (string) ($metadata->version ?? '');
        $versionComment = (string) ($metadata->version_comment ?? '');

        if (! hash_equals($expectedDatabase, $actualDatabase)) {
            throw new RuntimeException('The connected MySQL database does not match MYSQL_CONCURRENCY_DATABASE.');
        }

        if (str_contains(strtolower($version.' '.$versionComment), 'mariadb')) {
            throw new RuntimeException('The concurrency lane requires MySQL rather than MariaDB.');
        }

        if (version_compare($version, '8.0', '<')) {
            throw new RuntimeException('The concurrency lane requires MySQL 8.0 or newer.');
        }

        return [
            'database' => $actualDatabase,
            'version' => $version,
            'version_comment' => $versionComment,
        ];
    }

    private static function environmentValue(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
