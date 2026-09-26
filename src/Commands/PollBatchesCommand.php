<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Models\Batch;
use IsrarMinhas\FilamentAiVisibility\Runs\Economy;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use Throwable;

/**
 * Collects finished economy-mode batches. Scheduled every five minutes.
 */
class PollBatchesCommand extends Command
{
    protected $signature = 'ai-visibility:poll-batches';

    protected $description = 'Store the answers of finished AI Visibility batches (economy mode)';

    public function handle(Economy $economy): int
    {
        $batches = Batch::query()->withoutGlobalScopes()
            ->where('status', Batch::SUBMITTED)
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            // One batch's problem must not stop the others.
            try {
                $outcome = Tenancy::as($batch->tenant_id, fn () => $economy->poll($batch));
            } catch (Throwable $e) {
                report($e);
                $outcome = 'error: ' . $e->getMessage();
            }

            $this->components->twoColumnDetail("{$batch->engine} batch #{$batch->getKey()}", (string) $outcome);
        }

        if ($batches->isEmpty()) {
            $this->components->info('No batches in progress.');
        }

        return self::SUCCESS;
    }
}
