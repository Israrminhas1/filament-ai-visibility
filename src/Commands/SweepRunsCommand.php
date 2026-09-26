<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Runs\RunSweeper;

/**
 * Closes runs whose queue jobs were lost. Also done by `ai-visibility:run --due`.
 */
class SweepRunsCommand extends Command
{
    protected $signature = 'ai-visibility:sweep-runs';

    protected $description = 'Close AI Visibility runs that stopped making progress (lost queue jobs)';

    public function handle(RunSweeper $sweeper): int
    {
        $closed = $sweeper->sweep();

        $this->components->info($closed === []
            ? 'No stuck runs.'
            : 'Closed ' . count($closed) . ' stuck runs: #' . implode(', #', $closed) . '.');

        return self::SUCCESS;
    }
}
