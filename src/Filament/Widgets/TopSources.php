<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class TopSources extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.top-sources';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 1;

    public int $limit = 10;

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();
        $rows = $filters ? $this->metrics()->topSources($filters, $this->limit) : collect();
        $answers = $filters ? max(1, $this->metrics()->summary($filters)['answers']) : 1;

        return [
            'rows' => $rows->map(fn ($row) => [
                'domain' => $row->domain,
                'category' => SourceCategory::label($row->category),
                'color' => SourceCategory::color($row->category),
                'answers' => $row->answers,
                'share' => round($row->answers / $answers * 100),
            ]),
        ];
    }
}
