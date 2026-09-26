<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Events\RunStarted;
use IsrarMinhas\FilamentAiVisibility\Exceptions\RunNotStarted;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Jobs\SubmitBatchJob;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Model;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Support\CostEstimator;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;

/**
 * Checks everything that could make a run wasteful or impossible, then
 * creates the run and queues one job per prompt × engine × sample.
 */
class RunPlanner
{
    public function __construct(
        protected Settings $settings,
        protected EngineManager $engines,
        protected Spend $spend,
    ) {}

    /**
     * @param  array<int>|null  $promptIds  Only these prompts (active or not); default all active prompts.
     *
     * @throws RunNotStarted
     */
    public function start(Brand $brand, RunTrigger $trigger = RunTrigger::Manual, ?array $promptIds = null, ?int $userId = null): Run
    {
        $this->guard($brand, $trigger);

        $engines = $this->engines->usable($brand);

        if ($engines === []) {
            $enabled = $this->engines->enabled($brand);

            throw new RunNotStarted($enabled === []
                ? 'No engines are enabled. Turn on at least one engine in Settings.'
                : 'Every enabled engine is paused. See the Health page for how to fix it.');
        }

        $prompts = $brand->prompts()
            ->when($promptIds !== null, fn ($query) => $query->whereKey($promptIds), fn ($query) => $query->where('status', PromptStatus::Active))
            ->pluck('id');

        if ($prompts->isEmpty()) {
            throw new RunNotStarted("{$brand->name} has no active prompts to run.");
        }

        $samples = max(1, min(5, (int) $brand->setting('runs.samples', 1)));
        $estimate = CostEstimator::costPerRun($prompts->count(), $engines, $samples);
        $remaining = $this->spend->remaining($brand);

        if ($remaining !== null && $estimate > $remaining && $this->settings->get('budget.stop_at_budget', true)) {
            throw new RunNotStarted(sprintf(
                'This run would cost about %s, but only %s of the monthly budget is left.',
                CostEstimator::format($estimate),
                CostEstimator::format($remaining),
            ));
        }

        $run = DB::transaction(function () use ($brand, $trigger, $userId, $engines, $prompts, $samples, $estimate) {
            $run = Run::query()->create([
                'tenant_id' => $brand->tenant_id,
                'brand_id' => $brand->getKey(),
                'trigger' => $trigger,
                'triggered_by' => $userId,
                'status' => RunStatus::Running,
                'engines' => $engines,
                'results_total' => $prompts->count() * count($engines) * $samples,
                'estimated_cost_usd' => $estimate,
                'started_at' => now(),
            ]);

            $rows = [];
            $now = now();

            foreach ($prompts as $promptId) {
                foreach ($engines as $engine) {
                    for ($sample = 1; $sample <= $samples; $sample++) {
                        $rows[] = [
                            'tenant_id' => $brand->tenant_id,
                            'run_id' => $run->getKey(),
                            'brand_id' => $brand->getKey(),
                            'prompt_id' => $promptId,
                            'engine' => $engine,
                            'model' => $this->engines->model($engine, $brand),
                            'sample' => $sample,
                            'status' => ResultStatus::Pending->value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table(Model::prefixedTable('results'))->insert($chunk);
            }

            $brand->forceFill(['last_run_at' => now()])->save();

            return $run;
        });

        $this->dispatch($run);

        RunStarted::dispatch($run);

        return $run;
    }

    /**
     * Queue the skipped results of a finished run again, e.g. after an engine is back.
     */
    public function retrySkipped(Run $run): int
    {
        $skipped = $run->results()->where('status', ResultStatus::Skipped)->count();

        if ($skipped === 0) {
            return 0;
        }

        DB::transaction(function () use ($run, $skipped) {
            $run->results()->where('status', ResultStatus::Skipped)->update([
                'status' => ResultStatus::Pending->value,
                'skip_reason' => null,
            ]);

            $run->forceFill([
                'status' => RunStatus::Running,
                'status_reason' => null,
                'results_skipped' => $run->results_skipped - $skipped,
                'finished_at' => null,
            ])->save();
        });

        $this->dispatch($run, realtimeOnly: true);

        return $skipped;
    }

    /**
     * One job per result, or batches for engines in economy mode (scheduled runs only).
     */
    protected function dispatch(Run $run, bool $realtimeOnly = false): void
    {
        $economy = app(Economy::class);
        $batched = [];

        $run->results()
            ->where('status', ResultStatus::Pending)
            ->select(['id', 'engine'])
            ->orderBy('id')
            ->each(function (Result $result) use ($run, $economy, $realtimeOnly, &$batched) {
                if (! $realtimeOnly && $economy->applies($run, $result->engine)) {
                    $batched[$result->engine][] = $result->getKey();

                    return;
                }

                RunResultJob::dispatch($result->getKey(), $result->engine, $run->tenant_id);
            });

        foreach ($batched as $engine => $ids) {
            foreach (array_chunk($ids, (int) config('ai-visibility.economy.max_batch_size', 1000)) as $chunk) {
                SubmitBatchJob::dispatch($run->getKey(), $engine, $chunk, $run->tenant_id);
            }
        }
    }

    /**
     * @throws RunNotStarted
     */
    protected function guard(Brand $brand, RunTrigger $trigger): void
    {
        if (config('ai-visibility.require_setup', true) && ! $this->settings->isSetupComplete()) {
            throw new RunNotStarted('Finish the AI Visibility setup first.');
        }

        if ($this->settings->killSwitch()) {
            throw new RunNotStarted('"Pause everything" is on in Settings.');
        }

        if ($trigger === RunTrigger::Schedule && ! $brand->is_active) {
            throw new RunNotStarted("Tracking is off for {$brand->name}.");
        }

        if ($brand->paused_reason === 'budget' && $this->spend->overBudget($brand)) {
            throw new RunNotStarted("{$brand->name} has used its monthly budget.");
        }

        if ($trigger === RunTrigger::Manual && config('queue.connections.' . SystemHealth::queueConnection() . '.driver') === 'sync') {
            throw new RunNotStarted('The queue runs jobs synchronously. Set QUEUE_CONNECTION to database or redis and start a queue worker.');
        }

        $maxPerDay = (int) $brand->setting('limits.max_runs_per_brand_per_day', 0);

        if ($maxPerDay > 0 && $brand->runs()->where('created_at', '>=', now()->startOfDay())->count() >= $maxPerDay) {
            throw new RunNotStarted("{$brand->name} has reached its limit of {$maxPerDay} runs today.");
        }
    }

    /**
     * Whether a brand's scheduled run is due now.
     */
    public function isDue(Brand $brand, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $frequency = $brand->run_frequency;

        if (! $brand->is_active || $frequency === RunFrequency::Manual) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', (string) $this->settings->get('runs.time', '03:00')) + [1 => 0]);

        // The most recent scheduled slot at or before now.
        $slot = $now->copy()->setTime($hour, $minute);

        if ($slot->gt($now)) {
            $slot = $slot->subDay();
        }

        if ($brand->last_run_at === null) {
            return true;
        }

        return $frequency === RunFrequency::Daily
            ? $brand->last_run_at->lt($slot)
            : $brand->last_run_at->lt($slot->copy()->subDays(7)->addHour());
    }

    /**
     * Labels for the engines of a run, for display.
     *
     * @param  array<string>  $engines
     * @return array<string>
     */
    public static function engineLabels(array $engines): array
    {
        $registry = app(EngineRegistry::class);

        return array_map(fn ($key) => $registry->has($key) ? $registry->get($key)->label() : $key, $engines);
    }
}
