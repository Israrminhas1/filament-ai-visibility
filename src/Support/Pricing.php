<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

/**
 * Cost of one AI call: token prices (from AI Monitor when installed and
 * priced, otherwise config) plus a per-search fee.
 */
class Pricing
{
    public function cost(string $engine, ?string $model, ?int $inputTokens, ?int $outputTokens, int $searches = 0): float
    {
        $tokens = AiMonitor::cost($engine, $model, $inputTokens, $outputTokens)
            ?? $this->tokenCost($engine, $model, $inputTokens, $outputTokens);

        $searchFee = (float) config("ai-visibility.pricing.search_fee.{$engine}", 0);

        return round($tokens + $searches * $searchFee, 8);
    }

    public function tokenCost(string $engine, ?string $model, ?int $inputTokens, ?int $outputTokens): float
    {
        [$input, $output] = $this->perMillion($engine, $model);

        return (($inputTokens ?? 0) * $input + ($outputTokens ?? 0) * $output) / 1_000_000;
    }

    /**
     * @return array{0: float, 1: float} USD per 1M input and output tokens.
     */
    public function perMillion(string $engine, ?string $model): array
    {
        $models = config('ai-visibility.pricing.models', []);
        $model = strtolower((string) $model);

        if (isset($models[$model])) {
            return $models[$model];
        }

        // Dated snapshots ("gpt-5-mini-2025-08-07") use their base model's price; longest match wins.
        $best = null;

        foreach (array_keys($models) as $base) {
            if (str_starts_with($model, $base . '-') && ($best === null || strlen($base) > strlen($best))) {
                $best = $base;
            }
        }

        return $best ? $models[$best] : (config("ai-visibility.pricing.fallback.{$engine}") ?? [0.0, 0.0]);
    }
}
