<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class EngineVisibilityChart extends ChartWidget
{
    use InteractsWithReportFilters;

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 1;

    protected ?string $heading = 'Visibility by engine';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $filters = $this->reportFilters();
        $rows = $filters ? $this->metrics()->byEngine($filters) : collect();

        return [
            'labels' => $rows->keys()->map(fn ($engine) => ResultResource::engineLabel($engine))->all(),
            'datasets' => [[
                'label' => 'Visibility (%)',
                'data' => $rows->pluck('visibility')->all(),
                'backgroundColor' => $rows->keys()->map(fn ($engine) => VisibilityTrendChart::ENGINE_COLORS[$engine] ?? '#6b7280')->all(),
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['min' => 0, 'max' => 100]],
        ];
    }
}
