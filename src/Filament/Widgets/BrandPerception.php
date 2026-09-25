<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\Enums\Recommendation;
use IsrarMinhas\FilamentAiVisibility\Enums\Sentiment;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;

/**
 * How AI answers talk about the brand: sentiment, how strongly it is
 * recommended, and the words used to describe it.
 */
class BrandPerception extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.brand-perception';

    protected static ?int $sort = 7;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();
        $perception = $filters ? app(CompetitorMetrics::class)->perception($filters) : null;

        return [
            'brand' => $filters?->brand,
            'perception' => $perception,
            'sentiments' => Sentiment::cases(),
            'recommendations' => Recommendation::cases(),
        ];
    }
}
