<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class ShareOfVoiceChart extends ChartWidget
{
    use InteractsWithReportFilters;

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 1;

    protected ?string $heading = 'Share of voice';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public function getDescription(): ?string
    {
        return 'How often each brand is named, of all answers naming a tracked brand.';
    }

    protected function getData(): array
    {
        $filters = $this->reportFilters();
        $rows = $filters ? $this->metrics()->shareOfVoice($filters)->take(10) : collect();

        return [
            'labels' => $rows->pluck('name')->all(),
            'datasets' => [[
                'label' => 'Share of voice (%)',
                'data' => $rows->pluck('share')->all(),
                'backgroundColor' => $rows->pluck('color')->all(),
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
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['min' => 0, 'max' => 100]],
        ];
    }
}
