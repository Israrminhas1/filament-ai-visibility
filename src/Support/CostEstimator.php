<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

/**
 * Rough monthly cost of tracking, shown before anything is spent.
 */
class CostEstimator
{
    // How many recent answers the learned cost looks at, and how many it needs.
    public const SAMPLE_SIZE = 50;

    public const MIN_SAMPLES = 5;

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
     * @param  array<string, string>  $models  Model per engine, when known.
     */
    public static function costPerRun(int $prompts, array $engines, int $samples = 1, array $models = []): float
    {
        return array_sum(array_map(
            fn (string $engine) => $prompts * max(1, $samples) * static::perResult($engine, $models[$engine] ?? null),
            $engines,
        ));
    }

    /**
     * Expected cost of one answer. Learned from this tenant's last successful answers
     * (same model when there are enough, else same engine), because real costs often
     * differ a lot from the configured guess; the config is only the fallback.
     */
    public static function perResult(string $engine, ?string $model = null): float
    {
        $learned = static::learned($engine, $model) ?? ($model !== null ? static::learned($engine) : null);

        return $learned ?? (float) (config('ai-visibility.estimated_cost_per_result', [])[$engine] ?? 0.02);
    }

    protected static function learned(string $engine, ?string $model = null): ?float
    {
        $costs = Result::query()
            ->where('engine', $engine)
            ->when($model !== null, fn ($query) => $query->where('model', $model))
            ->where('status', ResultStatus::Success)
            ->whereNotNull('cost_usd')
            ->latest('id')
            ->limit(self::SAMPLE_SIZE)
            ->pluck('cost_usd');

        return $costs->count() >= self::MIN_SAMPLES ? (float) $costs->avg() : null;
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
