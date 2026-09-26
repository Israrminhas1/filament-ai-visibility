<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\Economy;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use Throwable;

/**
 * Sends one engine's share of a scheduled run to its batch API (economy mode).
 */
class SubmitBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<int>  $resultIds
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $engine,
        public readonly array $resultIds,
        public readonly int | string | null $tenantId,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.tracking', 'default'));
    }

    public function handle(): void
    {
        Tenancy::as($this->tenantId, function () {
            $run = Run::query()->find($this->runId);

            if ($run) {
                app(Economy::class)->submit($run, $this->engine, $this->resultIds);
            }
        });
    }

    /**
     * Something unexpected went wrong: answer the results in real time instead.
     */
    public function failed(?Throwable $exception): void
    {
        Tenancy::as($this->tenantId, fn () => app(Economy::class)->realtime($this->resultIds, $this->engine, $this->tenantId));
    }
}
