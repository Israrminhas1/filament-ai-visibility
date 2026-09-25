<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Models\Brand;
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
     * Remaining budget for the brand this month (the smaller of the tenant and brand budgets), or null for no budget.
     */
    public function remaining(?Brand $brand = null): ?float
    {
        $remaining = [];

        if (($budget = $this->monthlyBudget()) !== null) {
            $remaining[] = $budget - $this->thisMonth();
        }

        if ($brand && ($budget = $this->brandBudget($brand)) !== null) {
            $remaining[] = $budget - $this->thisMonth($brand);
        }

        return $remaining ? max(0.0, min($remaining)) : null;
    }

    public function overBudget(?Brand $brand = null): bool
    {
        $remaining = $this->remaining($brand);

        return $remaining !== null && $remaining <= 0;
    }
}
