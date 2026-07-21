<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\MySqlConcurrencyDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $permissionId = (int) requiredEnvironmentVariable('MYSQL_CONCURRENCY_PERMISSION_ID');
    $readyFile = requiredEnvironmentVariable('MYSQL_CONCURRENCY_READY_FILE');
    $userId = (int) requiredEnvironmentVariable('MYSQL_CONCURRENCY_USER_ID');

    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $application->make(ConsoleKernel::class)->bootstrap();
    MySqlConcurrencyDatabaseGuard::assertSafe();

    $permission = Permission::query()->findOrFail($permissionId);
    $user = User::query()->findOrFail($userId);
    $connection = DB::connection();
    $connectionId = (int) $connection->selectOne('select connection_id() as connection_id')->connection_id;

    if (file_put_contents($readyFile, json_encode(['connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException("Unable to write worker barrier file [{$readyFile}].");
    }

    try {
        $user->givePermissionTo($permission);
        $status = 200;
        $body = ['success' => true];
    } catch (QueryException $exception) {
        $status = 409;
        $body = [
            'success' => false,
            'sql_state' => $exception->errorInfo[0] ?? null,
            'driver_code' => $exception->errorInfo[1] ?? null,
        ];
    }

    fwrite(STDOUT, json_encode([
        'status' => $status,
        'body' => $body,
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
