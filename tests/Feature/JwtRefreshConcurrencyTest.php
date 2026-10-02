<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Manager;
use PHPOpenSourceSaver\JWTAuth\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class JwtRefreshConcurrencyTest extends TestCase
{
    #[DataProvider('sharedCacheProvider')]
    public function test_concurrent_refresh_of_one_token_only_issues_one_replacement(string $store, string $guard): void
    {
        $directory = sys_get_temp_dir().'/admin9-jwt-refresh-test-'.Str::uuid();
        File::ensureDirectoryExists($directory);
        $database = $directory.'/refresh_test.sqlite';
        touch($database);
        $processes = [];

        try {
            config([
                'database.default' => 'jwt_refresh_test',
                'database.connections.jwt_refresh_test' => [
                    ...config('database.connections.sqlite'),
                    'database' => $database,
                    'url' => null,
                ],
                'cache.default' => $store,
                'cache.prefix' => 'jwt-refresh-test-',
                'cache.stores.file.path' => $directory.'/cache',
                'cache.stores.file.lock_path' => $directory.'/cache',
                'cache.stores.database.connection' => 'jwt_refresh_test',
                'cache.stores.database.lock_connection' => 'jwt_refresh_test',
            ]);
            Cache::forgetDriver($store);
            $this->app->forgetInstance('cache.store');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));

            $account = $guard === 'admin' ? User::factory()->create() : Member::factory()->create();
            $token = Auth::guard($guard)->login($account);
            $first = $processes[] = $this->startWorker($directory, $database, $store, $guard, $token, 'first');
            $this->waitForFile($directory.'/first-paused', $first);
            $second = $processes[] = $this->startWorker($directory, $database, $store, $guard, $token, 'second');
            $this->waitForFile($directory.'/second-ready', $second);

            $deadline = microtime(true) + 1;

            while ($second->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }

            touch($directory.'/release');
            $results = [];

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($guard === 'member' ? [200, 200] : [200, 401], $statuses);
            if ($guard === 'member') {
                $this->assertSame($results[0]['replacement_token'], $results[1]['replacement_token']);
                $this->assertSame(1, DB::table('member_token_refreshes')->count());
                $this->assertSame(1, DB::table('member_auth_sessions')->count());
            }
            $winner = collect($results)->firstWhere('status', 200);
            $this->assertSame($account->id, $winner['subject_id']);
            $this->assertTrue($winner['replacement_valid']);
            $this->assertNotSame(hash('sha256', $token), $winner['replacement_hash']);

            $this->expectException(TokenBlacklistedException::class);
            app(Manager::class)->decode(new Token($token));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }

            Cache::forgetDriver($store);
            DB::purge('jwt_refresh_test');
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sharedCacheProvider(): array
    {
        return [
            'file member' => ['file', 'member'],
            'database member' => ['database', 'member'],
            'file admin' => ['file', 'admin'],
            'database admin' => ['database', 'admin'],
        ];
    }

    private function startWorker(string $directory, string $database, string $store, string $guard, string $token, string $worker): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/jwt-refresh-concurrency-worker.php')], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'CACHE_STORE' => $store,
            'CACHE_PREFIX' => 'jwt-refresh-test-',
            'DB_CACHE_CONNECTION' => 'sqlite',
            'DB_CACHE_LOCK_CONNECTION' => 'sqlite',
            'DB_CACHE_TABLE' => 'cache',
            'DB_CACHE_LOCK_TABLE' => 'cache_locks',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'stderr',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            'JWT_SECRET' => (string) config('jwt.secret'),
            'JWT_BLACKLIST_ENABLED' => 'true',
            'JWT_SHOW_BLACKLIST_EXCEPTION' => 'true',
            'JWT_BLACKLIST_GRACE_PERIOD' => '0',
        ]);
        $process->setInput(json_encode(compact('directory', 'guard', 'token', 'worker'), JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->start();

        return $process;
    }

    private function waitForFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 5;

        while (! is_file($path)) {
            if (! $process->isRunning()) {
                $this->fail('Refresh worker exited before reaching the barrier: '.$process->getErrorOutput());
            }

            if (microtime(true) >= $deadline) {
                $this->fail('Worker did not reach refresh barrier.');
            }

            usleep(10000);
        }
    }
}
