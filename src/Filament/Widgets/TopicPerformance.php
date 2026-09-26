<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class TopicPerformance extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.topic-performance';

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();

        return [
            'rows' => $filters ? $this->metrics()->topics($filters) : collect(),
        ];
    }
}
