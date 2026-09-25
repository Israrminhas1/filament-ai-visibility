<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;

/**
 * Rough monthly cost of tracking, shown before anything is spent.
 */
class CostEstimator
{
    public static function runsPerMonth(RunFrequency | string | null $frequency): float
    {
        $frequency = $frequency instanceof RunFrequency ? $frequency : RunFrequency::tryFrom((string) $frequency);

        return match ($frequency) {
            RunFrequency::Daily => 30.0,
            RunFrequency::Weekly => 4.33,
            default => 0.0,
        };
    }

    /**
     * @param  array<string>  $engines
     */
    public static function costPerRun(int $prompts, array $engines, int $samples = 1): float
    {
        $perResult = config('ai-visibility.estimated_cost_per_result', []);

        return array_sum(array_map(
            fn (string $engine) => $prompts * max(1, $samples) * (float) ($perResult[$engine] ?? 0.02),
            $engines,
        ));
    }

    /**
     * @param  array<string>  $engines
     */
    public static function monthly(int $prompts, array $engines, int $samples, RunFrequency | string | null $frequency): float
    {
        return round(static::costPerRun($prompts, $engines, $samples) * static::runsPerMonth($frequency), 2);
    }

    public static function format(float $usd): string
    {
        return '$' . number_format($usd, $usd < 10 ? 2 : 0);
    }
}
