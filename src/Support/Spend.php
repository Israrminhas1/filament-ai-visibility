<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;

/**
 * Records every AI call and answers "how much has been spent this month".
 */
class Spend
{
    public function __construct(
        protected Settings $settings,
    ) {}

    public function record(string $engine, ?string $model, string $purpose, ?int $inputTokens, ?int $outputTokens, int $searches, float $cost, ?Brand $brand = null): Usage
    {
        $usage = Usage::query()->create([
            'brand_id' => $brand?->getKey(),
            'engine' => $engine,
            'model' => $model,
            'purpose' => $purpose,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'searches' => $searches,
            'cost_usd' => $cost,
        ]);

        AiMonitor::log([
            'provider' => $engine,
            'model' => $model ?? 'unknown',
            'request_type' => "ai-visibility:{$purpose}",
            'prompt_tokens' => $inputTokens,
            'completion_tokens' => $outputTokens,
            'cost_usd' => $cost,
            'status' => 'success',
            'meta' => ['brand_id' => $brand?->getKey(), 'searches' => $searches],
        ]);

        return $usage;
    }

    public function thisMonth(?Brand $brand = null): float
    {
        return (float) Usage::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->when($brand, fn ($query) => $query->where('brand_id', $brand->getKey()))
            ->sum('cost_usd');
    }

    public function monthlyBudget(): ?float
    {
        $budget = $this->settings->get('budget.monthly_usd');

        return filled($budget) && (float) $budget > 0 ? (float) $budget : null;
    }

    public function brandBudget(Brand $brand): ?float
    {
        $budget = $brand->settings['budget']['monthly_usd'] ?? null;

        return filled($budget) && (float) $budget > 0 ? (float) $budget : null;
    }

    /**
     * Estimated cost of answers still to come in unfinished runs: each run's estimate
     * pro-rated by its unanswered results (answers in open batches are among them).
     */
    public function committed(?Brand $brand = null): float
    {
        return (float) Run::query()
            ->whereNull('finished_at')
            ->where('results_total', '>', 0)
            ->whereNotNull('estimated_cost_usd')
            ->when($brand, fn ($query) => $query->where('brand_id', $brand->getKey()))
            ->get(['id', 'estimated_cost_usd', 'results_total', 'results_done', 'results_failed', 'results_skipped'])
            ->sum(fn (Run $run) => (float) $run->estimated_cost_usd * max(0, $run->results_total - $run->processed()) / $run->results_total);
    }

    /**
     * Budget left for the brand this month (the smaller of the tenant and brand budgets), or null
     * for no budget. By default the expected cost of runs still in progress is already taken off,
     * so new runs cannot overshoot the budget; pass false for what has actually been spent.
     */
    public function remaining(?Brand $brand = null, bool $includeCommitted = true): ?float
    {
        $remaining = [];

        if (($budget = $this->monthlyBudget()) !== null) {
            $remaining[] = $budget - $this->thisMonth() - ($includeCommitted ? $this->committed() : 0.0);
        }

        if ($brand && ($budget = $this->brandBudget($brand)) !== null) {
            $remaining[] = $budget - $this->thisMonth($brand) - ($includeCommitted ? $this->committed($brand) : 0.0);
        }

        return $remaining ? max(0.0, min($remaining)) : null;
    }

    /**
     * Whether actual spending has used up the budget. Runs in progress are not counted
     * here, or a run's own estimate would stop its own answers.
     */
    public function overBudget(?Brand $brand = null): bool
    {
        $remaining = $this->remaining($brand, includeCommitted: false);

        return $remaining !== null && $remaining <= 0;
    }
}
