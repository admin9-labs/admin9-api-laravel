<?php

namespace Tests\Support;

use App\Support\ApiRouting;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

trait RunsConcurrentMySqlRequests
{
    /**
     * @param  array<int, array{method: string, path: string, payload: array<string, mixed>, token: string, upload?: string, prune?: bool}>  $requests
     * @return array<int, array{status: int, body: array<string, mixed>}>
     */
    protected function raceRequests(Model $lockTarget, array $requests, ?Closure $whileLocked = null): array
    {
        $directory = sys_get_temp_dir().'/admin9-feature-race-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $workers = [];
        DB::beginTransaction();

        try {
            $lockTarget->newQuery()->whereKey($lockTarget->getKey())->lockForUpdate()->firstOrFail();
            $whileLocked?->__invoke();
            $database = config('database.connections.mysql');
            foreach ($requests as $index => $request) {
                $ready = $directory.'/'.$index.'.json';
                $process = new Process([PHP_BINARY, base_path('tests/Support/mysql-concurrency-request.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_URL' => 'http://localhost',
                    'APP_CONFIG_CACHE' => $directory.'/config.php', 'BCRYPT_ROUNDS' => '4',
                    'CACHE_STORE' => (string) config('cache.default'), 'CACHE_PREFIX' => (string) config('cache.prefix'),
                    'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => (string) $database['database'],
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
                    'DB_SOCKET' => (string) $database['unix_socket'], 'DB_URL' => '',
                    'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_LOCK_CONNECTION' => 'mysql',
                    'JWT_SECRET' => (string) config('jwt.secret'), 'LOG_CHANNEL' => 'stderr',
                    'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                    'MYSQL_CONCURRENCY_DATABASE' => (string) $database['database'],
                    'MYSQL_CONCURRENCY_METHOD' => $request['method'],
                    'MYSQL_CONCURRENCY_PAYLOAD' => json_encode($request['payload'] === [] ? new \stdClass : $request['payload'], JSON_THROW_ON_ERROR),
                    'MYSQL_CONCURRENCY_EMPTY_BODY' => $request['payload'] === [] ? '1' : '0',
                    'MYSQL_CONCURRENCY_PRUNE_SESSIONS' => ($request['prune'] ?? false) ? '1' : '0',
                    'MYSQL_CONCURRENCY_READY_FILE' => $ready, 'MYSQL_CONCURRENCY_TOKEN' => $request['token'],
                    'MYSQL_CONCURRENCY_URI' => ApiRouting::path($request['path']),
                    'MYSQL_CONCURRENCY_UPLOAD' => $request['upload'] ?? '',
                    'MYSQL_CONCURRENCY_STORAGE_PATH' => Storage::disk('public')->path(''),
                ]);
                $process->setTimeout(20)->start();
                $workers[] = ['process' => $process, 'ready' => $ready];
            }

            $deadline = microtime(true) + 10;
            do {
                $ids = [];
                foreach ($workers as $worker) {
                    $this->assertTrue($worker['process']->isRunning(), $worker['process']->getOutput().$worker['process']->getErrorOutput());
                    if (is_file($worker['ready'])) {
                        $ids[] = json_decode(file_get_contents($worker['ready']), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
                    }
                }
                if (count($ids) === 2 && count(DB::select(
                    'select distinct threads.processlist_id from performance_schema.data_lock_waits waits join performance_schema.threads threads on threads.thread_id = waits.requesting_thread_id where threads.processlist_id in (?, ?)',
                    $ids,
                )) === 2) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertLessThan($deadline, microtime(true), 'Both HTTP workers must reach verified MySQL row-lock waits.');
            DB::commit();

            return array_map(function (array $worker): array {
                $worker['process']->wait();
                $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());

                return json_decode($worker['process']->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop(1);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
