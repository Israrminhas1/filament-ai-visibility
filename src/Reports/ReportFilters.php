<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Carbon\CarbonImmutable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;

/**
 * The slice of data a report covers: one brand, a period, and optionally one engine and topic.
 */
final class ReportFilters
{
    public function __construct(
        public readonly Brand $brand,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
        public readonly ?string $engine = null,
        public readonly ?int $topicId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function periods(): array
    {
        return [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];
    }

    /**
     * Build filters from page filter state, falling back to the first brand and 30 days.
     *
     * @param  array<string, mixed>|null  $state
     */
    public static function fromState(?array $state): ?self
    {
        $brand = filled($state['brand'] ?? null)
            ? Brand::query()->find($state['brand'])
            : Brand::query()->orderBy('name')->first();

        if (! $brand) {
            return null;
        }

        $days = (int) ($state['period'] ?? 30);
        $days = array_key_exists($days, static::periods()) ? $days : 30;

        return new self(
            brand: $brand,
            from: CarbonImmutable::now()->subDays($days - 1)->startOfDay(),
            until: CarbonImmutable::now()->endOfDay(),
            engine: filled($state['engine'] ?? null) ? (string) $state['engine'] : null,
            topicId: filled($state['topic'] ?? null) ? (int) $state['topic'] : null,
        );
    }

    public function days(): int
    {
        return (int) round($this->from->diffInDays($this->until)) ?: 1;
    }

    /**
     * The same-length period just before this one, for comparisons.
     */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->until);

        return new self($this->brand, $this->from->subSeconds((int) $length + 1), $this->from->subSecond(), $this->engine, $this->topicId);
    }
}
