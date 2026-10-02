<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = (string) config('database.default');

        config([
            'queue.connections.database.connection' => $connection,
            'queue.connections.database.table' => 'jobs',
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => $connection,
            'queue.batching.database' => $connection,
            'cache.stores.database.connection' => $connection,
            'cache.stores.database.lock_connection' => $connection,
            'cache.stores.database.table' => 'cache',
            'cache.stores.database.lock_table' => 'cache_locks',
        ]);
    }

    public function test_database_queue_monitor_emits_an_alert_only_for_the_target_queue_at_the_threshold(): void
    {
        Event::fake([QueueBusy::class]);
        $queue = Queue::connection('database');
        $queue->pushRaw('{}', 'operations');
        $queue->pushRaw('{}', 'unrelated');
        $queue->pushRaw('{}', 'unrelated');

        $this->assertSame(0, Artisan::call('queue:monitor', [
            'queues' => 'database:operations',
            '--max' => 2,
            '--json' => true,
        ]));
        $this->assertSame(1, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0]['size']);
        Event::assertNotDispatched(QueueBusy::class);

        $queue->pushRaw('{}', 'operations');

        $this->assertSame(0, Artisan::call('queue:monitor', [
            'queues' => 'database:operations',
            '--max' => 2,
            '--json' => true,
        ]));
        Event::assertDispatchedTimes(QueueBusy::class, 1);
        Event::assertDispatched(QueueBusy::class, fn (QueueBusy $event): bool => $event->connectionName === 'database'
            && $event->queue === 'operations'
            && $event->size === 2);
        $this->assertDatabaseCount('jobs', 4);
    }

    public function test_failed_job_pruning_preserves_the_retention_boundary_and_recent_failures(): void
    {
        $this->freezeTime();
        $boundary = now()->subHours(config('queue.failed.prune_hours'));

        foreach (['expired' => $boundary->copy()->subSecond(), 'boundary' => $boundary, 'recent' => now()] as $uuid => $failedAt) {
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Test failure',
                'failed_at' => $failedAt,
            ]);
        }

        $this->assertSame(0, Artisan::call('queue:prune-failed', ['--hours' => config('queue.failed.prune_hours')]));
        $this->assertSame(['boundary', 'recent'], DB::table('failed_jobs')->orderBy('id')->pluck('uuid')->all());
    }

    public function test_batch_pruning_obeys_finished_unfinished_and_cancelled_retention_boundaries(): void
    {
        $this->freezeTime();
        $finishedBoundary = now()->subHours(config('queue.batching.prune_hours'))->timestamp;
        $unfinishedBoundary = now()->subHours(config('queue.batching.prune_unfinished_hours'))->timestamp;
        $cancelledBoundary = now()->subHours(config('queue.batching.prune_cancelled_hours'))->timestamp;

        foreach ([
            ['id' => 'finished-expired', 'created_at' => $finishedBoundary - 3600, 'finished_at' => $finishedBoundary - 1],
            ['id' => 'finished-boundary', 'created_at' => $finishedBoundary - 3600, 'finished_at' => $finishedBoundary],
            ['id' => 'unfinished-expired', 'created_at' => $unfinishedBoundary - 1],
            ['id' => 'unfinished-boundary', 'created_at' => $unfinishedBoundary],
            ['id' => 'cancelled-expired', 'created_at' => $cancelledBoundary - 1, 'cancelled_at' => now()->timestamp, 'finished_at' => now()->timestamp],
            ['id' => 'cancelled-boundary', 'created_at' => $cancelledBoundary, 'cancelled_at' => now()->timestamp, 'finished_at' => now()->timestamp],
            ['id' => 'recent', 'created_at' => now()->timestamp],
        ] as $batch) {
            DB::table('job_batches')->insert([
                'name' => 'Operations test',
                'total_jobs' => 1,
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'failed_job_ids' => '[]',
                'options' => null,
                'cancelled_at' => null,
                'finished_at' => null,
                ...$batch,
            ]);
        }

        $this->assertSame(0, Artisan::call('queue:prune-batches', [
            '--hours' => config('queue.batching.prune_hours'),
            '--unfinished' => config('queue.batching.prune_unfinished_hours'),
            '--cancelled' => config('queue.batching.prune_cancelled_hours'),
        ]));
        $this->assertSame(
            ['cancelled-boundary', 'finished-boundary', 'recent', 'unfinished-boundary'],
            DB::table('job_batches')->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_database_cache_persists_across_store_instances_and_locks_keep_owner_isolation(): void
    {
        $this->freezeTime();
        $key = 'operations-'.Str::uuid();
        $cache = Cache::store('database');
        $cache->put($key, ['enabled' => true], 60);
        $firstOwner = $cache->lock($key, 60);
        $this->assertTrue($firstOwner->get());

        Cache::forgetDriver('database');
        $otherStore = Cache::store('database');
        $this->assertSame(['enabled' => true], $otherStore->get($key));
        $nextOwner = $otherStore->lock($key, 60);
        $this->assertFalse($nextOwner->get());
        $this->assertFalse($nextOwner->release());

        $this->travel(61)->seconds();

        $this->assertNull($otherStore->get($key));
        $this->assertTrue($nextOwner->get());
        $this->assertFalse($firstOwner->release());
        $this->assertTrue($nextOwner->isOwnedByCurrentProcess());
        $this->assertTrue($nextOwner->release());
    }
}
