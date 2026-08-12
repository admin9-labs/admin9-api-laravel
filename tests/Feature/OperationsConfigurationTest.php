<?php

namespace Tests\Feature;

use App\Listeners\LogQueueBusy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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

        $this->assertSame('Admin9 API', $environmentDefaults['APP_NAME']);
        $this->assertSame('http://localhost:8000', $environmentDefaults['APP_URL']);
        $this->assertArrayNotHasKey('ADMIN_BOOTSTRAP_NAME', $environmentDefaults);
        $this->assertArrayNotHasKey('ADMIN_BOOTSTRAP_EMAIL', $environmentDefaults);
        $this->assertArrayNotHasKey('ADMIN_BOOTSTRAP_PASSWORD', $environmentDefaults);
        $this->assertFileDoesNotExist(config_path('admin.php'));
        $this->assertSame('hello@admin9.dev', $environmentDefaults['MAIL_FROM_ADDRESS']);
        $this->assertSame('${APP_NAME}', $environmentDefaults['MAIL_FROM_NAME']);
        $this->assertStringContainsString('`admin@admin9.dev` / `password`', file_get_contents(base_path('README.md')));
        $this->assertStringContainsString('`member@admin9.dev` / `Member-password-123`', file_get_contents(base_path('README.md')));
        $this->assertStringContainsString("env('APP_NAME', 'Admin9 API')", file_get_contents(config_path('app.php')));
        $this->assertStringContainsString("env('MAIL_FROM_ADDRESS', 'hello@admin9.dev')", file_get_contents(config_path('mail.php')));
        $this->assertStringContainsString("env('MAIL_FROM_NAME', env('APP_NAME', 'Admin9 API'))", file_get_contents(config_path('mail.php')));
        $this->assertStringContainsString("env('LOG_SLACK_USERNAME', env('APP_NAME', 'Admin9 API'))", file_get_contents(config_path('logging.php')));
        $this->assertSame('database', $environmentDefaults['QUEUE_CONNECTION']);
        $this->assertSame('public', $environmentDefaults['MEDIA_DISK']);
        $this->assertArrayNotHasKey('MEDIA_URL', $environmentDefaults);
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

    public function test_public_disk_url_prefers_media_url_and_falls_back_to_app_url_storage(): void
    {
        $this->assertSame(
            'https://api.example.test/storage',
            $this->publicDiskUrl('https://api.example.test/', null),
        );
        $this->assertSame(
            'https://media.example.test/storage',
            $this->publicDiskUrl('https://api.example.test/', 'https://media.example.test/storage/'),
        );
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

    private function publicDiskUrl(string $appUrl, ?string $mediaUrl): string
    {
        $repository = Env::getRepository();
        $originalAppUrl = $repository->get('APP_URL');
        $originalMediaUrl = $repository->get('MEDIA_URL');

        $repository->clear('APP_URL');
        $repository->clear('MEDIA_URL');
        $repository->set('APP_URL', $appUrl);

        if ($mediaUrl !== null) {
            $repository->set('MEDIA_URL', $mediaUrl);
        }

        try {
            /** @var array{disks: array{public: array{url: string}}} $configuration */
            $configuration = require config_path('filesystems.php');

            return $configuration['disks']['public']['url'];
        } finally {
            $repository->clear('APP_URL');
            $repository->clear('MEDIA_URL');

            if ($originalAppUrl !== null) {
                $repository->set('APP_URL', $originalAppUrl);
            }

            if ($originalMediaUrl !== null) {
                $repository->set('MEDIA_URL', $originalMediaUrl);
            }
        }
    }

    public function test_queue_busy_event_logs_structured_warning_to_operations_channel(): void
    {
        config([
            'logging.operations.queue_channel' => 'queue-operations',
            'queue.monitor.max_jobs' => 1000,
        ]);

        $queueLogger = Mockery::mock(LoggerInterface::class);
        $queueLogger->shouldReceive('warning')
            ->once()
            ->with('Queue backlog threshold reached', [
                'connection' => 'redis',
                'queue' => 'critical',
                'size' => 1250,
                'configured_threshold' => 1000,
            ]);

        Log::shouldReceive('channel')
            ->once()
            ->with('queue-operations')
            ->andReturn($queueLogger);

        Event::dispatch(new QueueBusy('redis', 'critical', 1250));
    }

    public function test_queue_busy_listener_is_discoverable_by_the_framework(): void
    {
        Artisan::call('event:list', [
            '--event' => QueueBusy::class,
            '--json' => true,
        ]);

        $events = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $events);
        $this->assertSame(QueueBusy::class, $events[0]['event']);
        $this->assertContains(LogQueueBusy::class.'@handle', $events[0]['listeners']);
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

    public function test_ci_uses_the_project_node_version_and_frozen_npm_lockfile(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertSame("22.23.1\n", file_get_contents(base_path('.node-version')));
        $this->assertStringContainsString('node-version-file: .node-version', $workflow);
        $this->assertStringContainsString('npm ci --ignore-scripts', $workflow);
        $this->assertFileExists(base_path('package-lock.json'));
        $this->assertFileDoesNotExist(base_path('pnpm-lock.yaml'));
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
            'php artisan db:seed --force',
            'php artisan admin:create',
            'trusted interactive terminal',
            'APP_ENV=production',
            'APP_DEBUG=false',
        ] as $expected) {
            $this->assertStringContainsString($expected, $readme);
        }

        foreach (['APP_KEY=', 'JWT_SECRET=', 'DB_PASSWORD='] as $secretShape) {
            $this->assertStringNotContainsString($secretShape, $readme);
        }

        $this->assertLessThan(
            strpos($readme, 'php artisan db:seed --force'),
            strpos($readme, 'php artisan migrate --force'),
        );
        $this->assertLessThan(
            strpos($readme, 'php artisan admin:create'),
            strpos($readme, 'php artisan db:seed --force'),
        );
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
