<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class SourceCategoriesChart extends ChartWidget
{
    use InteractsWithReportFilters;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 1;

    protected ?string $heading = 'Where answers get their information';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $filters = $this->reportFilters();
        $rows = $filters ? $this->metrics()->sourceCategories($filters) : collect();

        return [
            'labels' => $rows->keys()->map(fn ($category) => SourceCategory::label($category))->all(),
            'datasets' => [[
                'data' => $rows->values()->all(),
                'backgroundColor' => $rows->keys()->map(fn ($category) => SourceCategory::color($category))->all(),
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '55%',
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
