<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Carbon\CarbonInterface;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Batch;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Closes runs that stopped making progress: their queue jobs were lost (a
 * flushed queue, a crashed worker) so nothing will ever answer the rest.
 */
class RunSweeper
{
    public const LOST_JOB = 'Never answered: the queue job was lost.';

    public function __construct(
        protected RunProgress $progress,
    ) {}

    /**
     * @return array<int> IDs of the runs that were closed.
     */
    public function sweep(): array
    {
        $cutoff = now()->subHours(max(1, (int) config('ai-visibility.tracking.stale_run_hours', 6)));
        $closed = [];

        Run::query()->withoutGlobalScopes()
            ->whereNull('finished_at')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (Run $run) use ($cutoff, &$closed) {
                if (Tenancy::as($run->tenant_id, fn () => $this->sweepRun($run, $cutoff))) {
                    $closed[] = $run->getKey();
                }
            });

        return $closed;
    }

    protected function sweepRun(Run $run, CarbonInterface $cutoff): bool
    {
        // A result touched recently (answered, retried after an error) means it is still moving.
        if (Result::query()->withoutGlobalScopes()->where('run_id', $run->getKey())->where('updated_at', '>=', $cutoff)->exists()) {
            return false;
        }

        // Economy batches may take up to a day; the batch poller retries what they miss.
        $batchDeadline = now()->subHours((int) config('ai-visibility.economy.give_up_after_hours', 26));

        if (Batch::query()->withoutGlobalScopes()->where('run_id', $run->getKey())->where('status', Batch::SUBMITTED)->where('submitted_at', '>=', $batchDeadline)->exists()) {
            return false;
        }

        // Batches whose submission never completed will not be polled.
        Batch::query()->withoutGlobalScopes()
            ->where('run_id', $run->getKey())
            ->where('status', Batch::SUBMITTING)
            ->update(['status' => Batch::FAILED, 'error' => 'The submission did not complete.', 'completed_at' => now(), 'updated_at' => now()]);

        $this->progress->open($run)->update([
            'status' => ResultStatus::Failed->value,
            'error' => self::LOST_JOB,
            'updated_at' => now(),
        ]);

        $run = $this->progress->recount($run);
        $this->progress->closeIfDone($run);

        return $run->fresh()->finished_at !== null;
    }
}
