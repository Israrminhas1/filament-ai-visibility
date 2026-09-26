<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;

/**
 * Proves a worker is processing one of the AI Visibility queues. One is
 * sent to each queue the plugin uses.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public readonly string $queueName;

    public function __construct(?string $queue = null)
    {
        $this->queueName = $queue ?? SystemHealth::queueName();

        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue($this->queueName);
    }

    public function handle(): void
    {
        Heartbeat::beat(SystemHealth::queueHeartbeatName($this->queueName));
    }
}
