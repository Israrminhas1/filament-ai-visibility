<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;

class CompetitorLeaderboard extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.competitor-leaderboard';

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();

        return [
            'rows' => $filters ? app(CompetitorMetrics::class)->leaderboard($filters) : collect(),
        ];
    }
}
