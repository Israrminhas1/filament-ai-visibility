<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

/**
 * Cost of one AI call: token prices (from AI Monitor when installed and
 * priced, otherwise config) plus a per-search fee.
 */
class Pricing
{
    /**
     * @param  bool  $batch  Answered through a batch API: tokens are discounted, searches are not.
     */
    public function cost(string $engine, ?string $model, ?int $inputTokens, ?int $outputTokens, int $searches = 0, bool $batch = false): float
    {
        $tokens = AiMonitor::cost($engine, $model, $inputTokens, $outputTokens)
            ?? $this->tokenCost($engine, $model, $inputTokens, $outputTokens);

        if ($batch) {
            $tokens *= 1 - min(1, max(0, (float) config('ai-visibility.pricing.batch_discount', 0.5)));
        }

        return round($tokens + $searches * $this->searchFee($engine, $model), 8);
    }

    /**
     * USD per search: a model-specific fee (longest matching prefix) or the engine's.
     */
    public function searchFee(string $engine, ?string $model): float
    {
        $model = strtolower((string) $model);
        $best = null;

        foreach (array_keys(config('ai-visibility.pricing.search_fee_models', [])) as $prefix) {
            if (str_starts_with($model, strtolower($prefix)) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return (float) ($best !== null
            ? config('ai-visibility.pricing.search_fee_models')[$best]
            : config("ai-visibility.pricing.search_fee.{$engine}", 0));
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
