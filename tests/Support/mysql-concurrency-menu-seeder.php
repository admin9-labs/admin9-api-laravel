<?php

declare(strict_types=1);

use Database\Seeders\AdminAuditLogMenuSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\MySqlConcurrencyDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $readyFile = requiredEnvironmentVariable('MYSQL_CONCURRENCY_READY_FILE');
    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $application->make(Kernel::class)->bootstrap();
    MySqlConcurrencyDatabaseGuard::assertSafe();

    $connection = DB::connection();
    $connectionId = (int) $connection->selectOne('select connection_id() as connection_id')->connection_id;

    if (file_put_contents($readyFile, json_encode(['connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException("Unable to write worker barrier file [{$readyFile}].");
    }

    (new AdminAuditLogMenuSeeder)->run();

    fwrite(STDOUT, json_encode([
        'status' => 200,
        'body' => ['success' => true],
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $throwable) {
    fwrite(STDERR, json_encode([
        'type' => $throwable::class,
        'message' => $throwable->getMessage(),
    ], JSON_THROW_ON_ERROR));

    exit(1);
}

function requiredEnvironmentVariable(string $name): string
{
    $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

    if (! is_string($value) || $value === '') {
        throw new RuntimeException("Missing required worker environment variable [{$name}].");
    }

    return $value;
}
