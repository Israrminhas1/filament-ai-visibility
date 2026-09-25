<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Exceptions\RunNotStarted;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

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

        $started = 0;

        // Every tenant's brands; each is handled in its own tenant context.
        Brand::query()->withoutGlobalScopes()->where('is_active', true)->where('run_frequency', '!=', 'manual')
            ->orderBy('id')
            ->each(function (Brand $brand) use ($planner, &$started) {
                Tenancy::as($brand->tenant_id, function () use ($planner, $brand, &$started) {
                    if ($planner->isDue($brand)) {
                        $started += (int) $this->start($planner, $brand, RunTrigger::Schedule);
                    }
                });
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
}
