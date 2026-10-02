<?php

namespace Tests\Integration\MySql;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use App\Models\SystemConfig;
use App\Support\ApiRouting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\MySqlConcurrencyDatabaseGuard;
use Tests\TestCase;

#[Group('mysql-concurrency')]
class DictionaryConfigurationConcurrencyTest extends TestCase
{
    use InteractsWithAdminRbac;

    protected function setUp(): void
    {
        parent::setUp();

        MySqlConcurrencyDatabaseGuard::assertSafe();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_dictionary_type_deletion_serializes_with_item_creation(): void
    {
        $token = $this->managerTokenFor(['system.dictionary.create', 'system.dictionary.delete']);

        foreach (range(1, $this->rounds()) as $round) {
            $type = DictionaryType::factory()->create();
            $results = $this->raceRequests($type, $token, [
                ['method' => 'DELETE', 'path' => '/admin/dictionary-types/'.$type->id, 'payload' => []],
                ['method' => 'POST', 'path' => '/admin/dictionary-items', 'payload' => [
                    'dictionary_type_id' => $type->id, 'name' => 'Concurrent item', 'code' => 'created_'.$round,
                ]],
            ]);

            $this->assertOneWriteRejected($results);

            if ($results[1]['status'] === 200) {
                $this->assertModelExists($type);
                $this->assertDatabaseHas('dictionary_items', ['dictionary_type_id' => $type->id, 'code' => 'created_'.$round]);
            } else {
                $this->assertModelMissing($type);
                $this->assertDatabaseMissing('dictionary_items', ['dictionary_type_id' => $type->id]);
            }
        }
    }

    public function test_dictionary_type_deletion_serializes_with_item_moves(): void
    {
        $token = $this->managerTokenFor(['system.dictionary.update', 'system.dictionary.delete']);

        foreach (range(1, $this->rounds()) as $round) {
            $target = DictionaryType::factory()->create();
            $item = DictionaryItem::factory()->create(['code' => 'moving_'.$round]);
            $sourceId = $item->dictionary_type_id;
            $results = $this->raceRequests($target, $token, [
                ['method' => 'DELETE', 'path' => '/admin/dictionary-types/'.$target->id, 'payload' => []],
                ['method' => 'PATCH', 'path' => '/admin/dictionary-items/'.$item->id, 'payload' => [
                    'dictionary_type_id' => $target->id,
                ]],
            ]);

            $this->assertOneWriteRejected($results);
            $this->assertModelExists($item);
            $this->assertSame($results[1]['status'] === 200 ? $target->id : $sourceId, $item->fresh()->dictionary_type_id);
        }
    }

    public function test_concurrent_system_config_type_and_value_changes_remain_valid(): void
    {
        $token = $this->managerTokenFor(['system.config.update']);

        foreach (range(1, $this->rounds()) as $round) {
            $config = SystemConfig::factory()->create(['type' => SystemConfig::TYPE_STRING, 'value' => '123']);
            $results = $this->raceRequests($config, $token, [
                ['method' => 'PATCH', 'path' => '/admin/system-configs/'.$config->id, 'payload' => ['type' => SystemConfig::TYPE_INTEGER]],
                ['method' => 'PATCH', 'path' => '/admin/system-configs/'.$config->id, 'payload' => ['value' => 'not-an-integer']],
            ]);

            $this->assertOneWriteRejected($results);
            $config->refresh();
            $this->assertSame($results[0]['status'] === 200 ? SystemConfig::TYPE_INTEGER : SystemConfig::TYPE_STRING, $config->type);
            $this->assertSame($results[0]['status'] === 200 ? '123' : 'not-an-integer', $config->value);
        }
    }

    /**
     * @param  array<int, array{method: string, path: string, payload: array<string, mixed>}>  $requests
     * @return array<int, array{status: int, body: array<string, mixed>}>
     */
    private function raceRequests(Model $lockTarget, string $token, array $requests): array
    {
        $directory = sys_get_temp_dir().'/admin9-dictionary-config-race-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $workers = [];
        DB::beginTransaction();

        try {
            $lockTarget->newQuery()->whereKey($lockTarget->getKey())->lockForUpdate()->firstOrFail();

            foreach ($requests as $index => $request) {
                $readyFile = $directory.'/'.$index.'.json';
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/mysql-concurrency-request.php')],
                    base_path(),
                    $this->workerEnvironment($request, $token, $readyFile),
                );
                $process->setTimeout(20)->start();
                $workers[] = ['process' => $process, 'ready_file' => $readyFile];
            }

            $this->waitForBlockedWorkers($workers);
            DB::commit();
            $results = [];

            foreach ($workers as $worker) {
                $process = $worker['process'];
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsInt($result['status'] ?? null);
                $results[] = $result;
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop(1);
                }
            }

            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }

            rmdir($directory);
        }
    }

    /**
     * @param  array<int, array{process: Process, ready_file: string}>  $workers
     */
    private function waitForBlockedWorkers(array $workers): void
    {
        $deadline = microtime(true) + 10;

        do {
            $connectionIds = [];

            foreach ($workers as $worker) {
                $this->assertTrue($worker['process']->isRunning(), $worker['process']->getOutput().$worker['process']->getErrorOutput());

                if (is_file($worker['ready_file'])) {
                    $ready = json_decode((string) file_get_contents($worker['ready_file']), true);

                    if (is_int($ready['connection_id'] ?? null)) {
                        $connectionIds[] = $ready['connection_id'];
                    }
                }
            }

            if (count($connectionIds) === 2) {
                $this->assertCount(2, array_unique($connectionIds));
                $waits = DB::select(
                    <<<'SQL'
                        select distinct threads.processlist_id as connection_id
                        from performance_schema.data_lock_waits as waits
                        inner join performance_schema.threads as threads
                            on threads.thread_id = waits.requesting_thread_id
                        where threads.processlist_id in (?, ?)
                        SQL,
                    $connectionIds,
                );

                if (count($waits) === 2) {
                    return;
                }
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        $this->fail('Both HTTP workers must reach a verified MySQL row-lock wait before the race is released.');
    }

    /**
     * @param  array{method: string, path: string, payload: array<string, mixed>}  $request
     * @return array<string, string>
     */
    private function workerEnvironment(array $request, string $token, string $readyFile): array
    {
        $database = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_URL' => 'http://localhost',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => (string) $database['database'],
            'DB_HOST' => (string) $database['host'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_PORT' => (string) $database['port'],
            'DB_SOCKET' => (string) $database['unix_socket'],
            'DB_URL' => '',
            'DB_USERNAME' => (string) $database['username'],
            'JWT_SECRET' => (string) config('jwt.secret'),
            'LOG_CHANNEL' => 'stderr',
            'MAIL_MAILER' => 'array',
            'MYSQL_CONCURRENCY_DATABASE' => (string) $database['database'],
            'MYSQL_CONCURRENCY_METHOD' => $request['method'],
            'MYSQL_CONCURRENCY_PAYLOAD' => json_encode($request['payload'], JSON_THROW_ON_ERROR),
            'MYSQL_CONCURRENCY_READY_FILE' => $readyFile,
            'MYSQL_CONCURRENCY_TOKEN' => $token,
            'MYSQL_CONCURRENCY_URI' => ApiRouting::path($request['path']),
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
    }

    /**
     * @param  array<int, array{status: int, body: array<string, mixed>}>  $results
     */
    private function assertOneWriteRejected(array $results): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses, json_encode($results, JSON_THROW_ON_ERROR));
    }

    private function rounds(): int
    {
        $rounds = filter_var($_SERVER['MYSQL_CONCURRENCY_ROUNDS'] ?? $_ENV['MYSQL_CONCURRENCY_ROUNDS'] ?? getenv('MYSQL_CONCURRENCY_ROUNDS') ?: 3, FILTER_VALIDATE_INT);

        if (! is_int($rounds) || $rounds < 2 || $rounds > 10) {
            throw new RuntimeException('MYSQL_CONCURRENCY_ROUNDS must be an integer between 2 and 10.');
        }

        return $rounds;
    }
}
