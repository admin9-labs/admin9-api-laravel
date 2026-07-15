<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\TestCase;

class OperationsConfigurationTest extends TestCase
{
    public function test_scheduler_registers_only_built_in_operations_commands(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $this->assertCount(3, $events);

        $commands = $events->map(fn ($event) => $event->command)->all();

        $this->assertTrue(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'queue:prune-failed --hours=168')));
        $this->assertTrue(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')));
        $this->assertTrue(collect($commands)->contains(fn (string $command): bool => str_contains($command, "queue:monitor 'sync:default' --max=1000")));

        $this->assertSame([
            '15 1 * * *',
            '30 1 * * *',
            '*/5 * * * *',
        ], $events->map(fn ($event) => $event->getExpression())->all());

        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(1440, $event->expiresAt);
        }
    }

    public function test_operations_configuration_has_safe_defaults_without_secrets(): void
    {
        $environmentDefaults = parse_ini_file(base_path('.env.example'), false, INI_SCANNER_RAW);

        $this->assertSame('database', $environmentDefaults['QUEUE_CONNECTION']);
        $this->assertSame('database', $environmentDefaults['CACHE_STORE']);
        $this->assertSame('stack', $environmentDefaults['LOG_CHANNEL']);
        $this->assertSame(['single'], config('logging.channels.stack.channels'));
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertSame('redis', config('queue.connections.redis.driver'));
        $this->assertSame('deferred', config('queue.connections.deferred.driver'));
        $this->assertSame('background', config('queue.connections.background.driver'));
        $this->assertSame('failover', config('queue.connections.failover.driver'));
        $this->assertSame('cache_locks', config('cache.stores.database.lock_table'));
        $this->assertSame('storage', config('cache.stores.storage.driver'));
        $this->assertSame('redis', config('cache.stores.redis.driver'));
        $this->assertSame('failover', config('cache.stores.failover.driver'));
        $this->assertSame(168, config('queue.failed.prune_hours'));
        $this->assertSame(48, config('queue.batching.prune_hours'));
        $this->assertSame(72, config('queue.batching.prune_unfinished_hours'));
        $this->assertSame(72, config('queue.batching.prune_cancelled_hours'));
        $this->assertSame('sync:default', config('queue.monitor.queues'));
        $this->assertSame(1000, config('queue.monitor.max_jobs'));
        $this->assertSame('stack', config('logging.operations.scheduler_channel'));
        $this->assertSame('stack', config('logging.operations.queue_channel'));

        $serialized = json_encode([
            'queue' => config('queue.monitor'),
            'logging' => config('logging.operations'),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('secret', strtolower($serialized));
        $this->assertStringNotContainsString('password', strtolower($serialized));
        $this->assertStringNotContainsString('token', strtolower($serialized));
    }

    public function test_scheduler_failure_hooks_use_operations_log_channels(): void
    {
        config([
            'logging.operations.scheduler_channel' => 'scheduler-operations',
            'logging.operations.queue_channel' => 'queue-operations',
        ]);

        $schedulerLogger = Mockery::mock(LoggerInterface::class);
        $schedulerLogger->shouldReceive('warning')
            ->once()
            ->with('Failed to prune stale failed queue jobs');
        $schedulerLogger->shouldReceive('warning')
            ->once()
            ->with('Failed to prune stale queue batch records');

        $queueLogger = Mockery::mock(LoggerInterface::class);
        $queueLogger->shouldReceive('warning')
            ->once()
            ->with('Queue monitor command failed');

        Log::shouldReceive('channel')
            ->twice()
            ->with('scheduler-operations')
            ->andReturn($schedulerLogger);
        Log::shouldReceive('channel')
            ->once()
            ->with('queue-operations')
            ->andReturn($queueLogger);

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            $this->runFailureCallbacks($event);
        }
    }

    public function test_development_and_test_scripts_run_required_operations_processes(): void
    {
        /** @var array{scripts: array{dev: array<int, string>, test: array<int, string>}} $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains('@php artisan test --compact', $composer['scripts']['test']);

        $devCommand = implode(' ', $composer['scripts']['dev']);

        $this->assertStringContainsString('php artisan queue:listen --tries=1 --timeout=0', $devCommand);
        $this->assertStringContainsString('php artisan schedule:work', $devCommand);
        $this->assertStringContainsString('php artisan pail --timeout=0', $devCommand);
        $this->assertStringContainsString('--names=server,queue,schedule,logs,vite', $devCommand);
    }

    public function test_composer_hooks_keep_discovery_setup_and_formatting_scripts(): void
    {
        /** @var array{scripts: array<string, array<int, string>>} $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains('@php artisan package:discover --ansi', $composer['scripts']['post-autoload-dump']);
        $this->assertContains('@php artisan key:generate --ansi', $composer['scripts']['post-create-project-cmd']);
        $this->assertTrue(collect($composer['scripts']['post-create-project-cmd'])->contains(
            fn (string $command): bool => str_contains($command, 'database/database.sqlite') && str_contains($command, 'touch')
        ));
        $this->assertContains('@php artisan migrate --graceful --ansi', $composer['scripts']['post-create-project-cmd']);
        $this->assertSame(['./vendor/bin/pint --parallel'], $composer['scripts']['pint']);
        $this->assertContains('APP_URL=http://localhost php artisan scramble:export --env=local --no-interaction', $composer['scripts']['docs:api']);
        $this->assertSame('@docs:api:check', $composer['scripts']['check'][0]);
        $this->assertSame([
            '@docs:api',
            'git diff --exit-code -- docs/api.json',
        ], $composer['scripts']['docs:api:check']);
    }

    public function test_setup_script_creates_sqlite_database_before_migrations(): void
    {
        /** @var array{scripts: array{setup: array<int, string>}} $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $setup = $composer['scripts']['setup'];

        $sqliteIndex = collect($setup)->search(fn (string $command): bool => str_contains($command, 'database/database.sqlite')
            && str_contains($command, 'touch'));
        $migrateIndex = array_search('@php artisan migrate --force', $setup, true);
        $npmInstallIndex = array_search('npm ci --ignore-scripts', $setup, true);
        $buildIndex = array_search('npm run build', $setup, true);

        $this->assertIsInt($sqliteIndex);
        $this->assertIsInt($migrateIndex);
        $this->assertIsInt($npmInstallIndex);
        $this->assertIsInt($buildIndex);
        $this->assertLessThan($migrateIndex, $sqliteIndex);
        $this->assertLessThan($buildIndex, $npmInstallIndex);
    }

    public function test_health_route_and_schedule_list_are_bootable(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJson([
                'name' => config('app.name'),
                'status' => 'ok',
            ]);

        $this->get('/up')->assertOk();

        Artisan::call('schedule:list', ['--json' => true]);

        $scheduled = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));

        $this->assertSame([
            'Prune stale failed queue job records',
            'Prune stale queue batch records',
            'Monitor configured queue backlog',
        ], $scheduled->pluck('description')->all());
    }

    public function test_readme_documents_minimum_production_operations_checklist_without_secrets(): void
    {
        $readme = (string) file_get_contents(base_path('README.md'));

        foreach ([
            'php artisan migrate --force',
            'php artisan config:cache',
            'php artisan route:cache',
            'php artisan queue:work',
            'php artisan queue:restart',
            'php artisan schedule:run',
            'GET /up',
            'logging.operations',
            'ADMIN_BOOTSTRAP_PASSWORD',
            'php artisan db:seed --force',
        ] as $expected) {
            $this->assertStringContainsString($expected, $readme);
        }

        foreach (['APP_KEY=', 'JWT_SECRET=', 'DB_PASSWORD='] as $secretShape) {
            $this->assertStringNotContainsString($secretShape, $readme);
        }
    }

    private function runFailureCallbacks(object $event): void
    {
        $exitCode = new ReflectionProperty($event, 'exitCode');
        $exitCode->setAccessible(true);
        $exitCode->setValue($event, 1);

        $afterCallbacks = new ReflectionProperty($event, 'afterCallbacks');
        $afterCallbacks->setAccessible(true);

        foreach ($afterCallbacks->getValue($event) as $callback) {
            $callback(app());
        }
    }
}
