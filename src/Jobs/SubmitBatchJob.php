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

    /**
     * Never retried: a second attempt could send a second paid batch for the same
     * results. Anything that goes wrong is answered in real time by failed().
     */
    public int $tries = 1;

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
        Tenancy::as($this->tenantId, function () {
            $economy = app(Economy::class);

            // Results the provider already has are answered by that batch, not again in real time.
            $unsent = array_values(array_diff($this->resultIds, $economy->inOpenBatch($this->runId, $this->resultIds)));

            $economy->realtime($unsent, $this->engine, $this->tenantId);
        });
    }
}
