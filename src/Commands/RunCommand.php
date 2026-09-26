<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Exceptions\RunNotStarted;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Runs\RunSweeper;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use Throwable;

class RunCommand extends Command
{
    protected $signature = 'ai-visibility:run
        {--due : Start every brand whose scheduled run is due (used by the scheduler)}
        {--brand= : Start a run for this brand ID now}';

    protected $description = 'Start AI Visibility tracking runs';

    public function handle(RunPlanner $planner): int
    {
        if ($id = $this->option('brand')) {
            $brand = Brand::query()->withoutGlobalScopes()->find($id);

            if (! $brand) {
                $this->components->error("Brand [{$id}] not found.");

                return self::FAILURE;
            }

            return $this->start($planner, $brand, RunTrigger::Manual) ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('due')) {
            $this->components->error('Pass --due or --brand=ID.');

            return self::FAILURE;
        }

        $this->sweep();

        $started = 0;

        // Every tenant's brands; each is handled in its own tenant context.
        Brand::query()->withoutGlobalScopes()->where('is_active', true)->where('run_frequency', '!=', 'manual')
            ->orderBy('id')
            ->each(function (Brand $brand) use ($planner, &$started) {
                // One brand's problem must not stop the others.
                try {
                    Tenancy::as($brand->tenant_id, function () use ($planner, $brand, &$started) {
                        if (! $planner->isDue($brand)) {
                            return;
                        }

                        if ($this->budgetCommitted()) {
                            $this->components->warn("{$brand->name}: the monthly budget is already spent or committed to runs in progress.");

                            return;
                        }

                        $started += (int) $this->start($planner, $brand, RunTrigger::Schedule);
                    });
                } catch (Throwable $e) {
                    report($e);
                    $this->components->error("{$brand->name}: {$e->getMessage()}");
                }
            });

        $this->components->info("Started {$started} runs.");

        return self::SUCCESS;
    }

    protected function start(RunPlanner $planner, Brand $brand, RunTrigger $trigger): bool
    {
        return Tenancy::as($brand->tenant_id, function () use ($planner, $brand, $trigger) {
            try {
                $run = $planner->start($brand, $trigger);
                $this->components->info("{$brand->name}: run #{$run->getKey()} started with {$run->results_total} answers to collect.");

                return true;
            } catch (RunNotStarted $e) {
                $this->components->warn("{$brand->name}: {$e->getMessage()}");

                return false;
            }
        });
    }

    /**
     * Whether the tenant's budget is used up once runs in progress are paid for.
     */
    protected function budgetCommitted(): bool
    {
        if (! app(Settings::class)->get('budget.stop_at_budget', true)) {
            return false;
        }

        $remaining = app(Spend::class)->remaining();

        return $remaining !== null && $remaining <= 0;
    }

    /**
     * Close runs whose queue jobs were lost before starting new ones.
     */
    protected function sweep(): void
    {
        try {
            if ($closed = app(RunSweeper::class)->sweep()) {
                $this->components->warn('Closed ' . count($closed) . ' stuck runs: #' . implode(', #', $closed) . '.');
            }
        } catch (Throwable $e) {
            report($e);
            $this->components->error("Could not close stuck runs: {$e->getMessage()}");
        }
    }
}
