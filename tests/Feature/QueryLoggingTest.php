<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mitoop\LaravelQueryLogger\QueryLogger;
use Mitoop\LaravelQueryLogger\ServiceProvider;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class QueryLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryLogger::logWhen(static fn (): bool => true);

        parent::tearDown();
    }

    public function test_queries_use_the_sql_channel_and_evaluate_the_logging_condition_for_each_query(): void
    {
        Event::forget(QueryExecuted::class);
        config(['logging.query.enabled' => true]);
        $this->assertSame('debug', config('logging.channels.sql.level'));
        (new ServiceProvider($this->app))->boot();

        $enabled = false;
        QueryLogger::logWhen(static function () use (&$enabled): bool {
            return $enabled;
        });
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('sql')->andReturn($logger);
        $logger->shouldReceive('debug')->once()->withArgs(
            static fn (string $line): bool => preg_match('/^\[:memory:\] \[\d+(?:\.\d+)?(?:μs|ms|s)\] select 42 as answer$/u', $line) === 1,
        );

        DB::select('select ? as answer', [1]);
        $enabled = true;
        DB::select('select ? as answer', [42]);
        $enabled = false;
        DB::select('select ? as answer', [2]);
    }

    public function test_the_query_logging_switch_disables_logging(): void
    {
        Event::forget(QueryExecuted::class);
        config(['logging.query.enabled' => false]);
        (new ServiceProvider($this->app))->boot();
        Log::shouldReceive('channel')->never();

        $this->assertSame(42, DB::select('select 42 as answer')[0]->answer);
    }
}
