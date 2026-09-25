<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;

/**
 * Proves a worker is processing the AI Visibility queue.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(SystemHealth::queueName());
    }

    public function handle(): void
    {
        Heartbeat::beat(SystemHealth::queueHeartbeatName());
    }
}
