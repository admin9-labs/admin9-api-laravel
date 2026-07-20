<?php

namespace App\Listeners;

use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Log;

class LogQueueBusy
{
    /**
     * Handle the event.
     */
    public function handle(QueueBusy $event): void
    {
        Log::channel((string) config('logging.operations.queue_channel'))->warning(
            'Queue backlog threshold reached',
            [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'size' => $event->size,
                'configured_threshold' => (int) config('queue.monitor.max_jobs'),
            ],
        );
    }
}
