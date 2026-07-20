<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\Support\MySqlConcurrencyDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $method = requiredEnvironmentVariable('MYSQL_CONCURRENCY_METHOD');
    $payload = requiredEnvironmentVariable('MYSQL_CONCURRENCY_PAYLOAD');
    $readyFile = requiredEnvironmentVariable('MYSQL_CONCURRENCY_READY_FILE');
    $token = requiredEnvironmentVariable('MYSQL_CONCURRENCY_TOKEN');
    $uri = requiredEnvironmentVariable('MYSQL_CONCURRENCY_URI');

    $request = Request::create(
        uri: $uri,
        method: $method,
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ],
        content: $payload,
    );
    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $application->instance('request', $request);
    Facade::clearResolvedInstance('request');
    $kernel = $application->make(HttpKernel::class);
    $kernel->bootstrap();
    MySqlConcurrencyDatabaseGuard::assertSafe();

    $connection = DB::connection();
    $connectionId = (int) $connection->selectOne('select connection_id() as connection_id')->connection_id;
    $readyPayload = json_encode(['connection_id' => $connectionId], JSON_THROW_ON_ERROR);

    if (file_put_contents($readyFile, $readyPayload, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write worker barrier file [{$readyFile}].");
    }

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    fwrite(STDOUT, json_encode([
        'status' => $response->getStatusCode(),
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
