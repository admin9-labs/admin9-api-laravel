<?php

use App\Models\MemberAuthSession;
use App\Models\MemberTokenRefresh;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 调度框架运维命令与文件删除恢复；当前项目没有后台业务 Job。
Schedule::command('queue:prune-failed', [
    '--hours' => config('queue.failed.prune_hours'),
])
    ->dailyAt('01:15')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::channel((string) config('logging.operations.scheduler_channel'))->warning('Failed to prune stale failed queue jobs'))
    ->description('Prune stale failed queue job records');

Schedule::command('queue:prune-batches', [
    '--hours' => config('queue.batching.prune_hours'),
    '--unfinished' => config('queue.batching.prune_unfinished_hours'),
    '--cancelled' => config('queue.batching.prune_cancelled_hours'),
])
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onFailure(fn () => Log::channel((string) config('logging.operations.scheduler_channel'))->warning('Failed to prune stale queue batch records'))
    ->description('Prune stale queue batch records');

Schedule::command('queue:monitor', [
    config('queue.monitor.queues'),
    '--max' => config('queue.monitor.max_jobs'),
])
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::channel((string) config('logging.operations.queue_channel'))->warning('Queue monitor command failed'))
    ->description('Monitor configured queue backlog');

Schedule::command('files:recover-deletions')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::channel((string) config('logging.operations.scheduler_channel'))->warning('File deletion recovery failed'))
    ->description('Recover interrupted, previously authorized file deletions');

Schedule::command('model:prune', [
    '--model' => [MemberAuthSession::class, MemberTokenRefresh::class],
])
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::channel((string) config('logging.operations.scheduler_channel'))->warning('Member authentication pruning failed'))
    ->description('Prune inactive member sessions and expired refresh results');
