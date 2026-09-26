<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertEvaluator;
use IsrarMinhas\FilamentAiVisibility\Alerts\StallWatcher;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class AlertsCommand extends Command
{
    protected $signature = 'ai-visibility:alerts
        {--watch : Only check that the queue worker is alive}';

    protected $description = 'Check alert rules for every brand, and whether the queue worker has stopped';

    public function handle(AlertEvaluator $evaluator, StallWatcher $watcher): int
    {
        if ($watcher->checkQueue()) {
            $this->components->warn('The queue worker appears to have stopped; an alert was sent.');
        }

        if ($this->option('watch')) {
            return self::SUCCESS;
        }

        $sent = 0;

        foreach (Brand::query()->withoutGlobalScopes()->where('is_active', true)->get() as $brand) {
            $sent += Tenancy::as($brand->tenant_id, fn () => $evaluator->evaluate($brand));
        }

        $this->components->info("{$sent} alerts sent.");

        return self::SUCCESS;
    }
}
