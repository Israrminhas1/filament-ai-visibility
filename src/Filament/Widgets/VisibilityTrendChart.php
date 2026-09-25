<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class VisibilityTrendChart extends ChartWidget
{
    use InteractsWithReportFilters;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $heading = 'Visibility over time';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public const ENGINE_COLORS = [
        'openai' => '#10b981',
        'anthropic' => '#d97706',
        'gemini' => '#3b82f6',
        'grok' => '#111827',
        'perplexity' => '#14b8a6',
    ];

    public function getDescription(): ?string
    {
        return '% of answers mentioning the brand, per engine. Gaps are days without answers (e.g. a paused engine).';
    }

    protected function getData(): array
    {
        $filters = $this->reportFilters();

        if (! $filters) {
            return ['datasets' => [], 'labels' => []];
        }

        $trend = $this->metrics()->trend($filters);

        return [
            'labels' => array_map(fn ($day) => CarbonImmutable::parse($day)->format('M j'), $trend['labels']),
            'datasets' => collect($trend['series'])->map(fn (array $values, string $engine) => [
                'label' => ResultResource::engineLabel($engine),
                'data' => $values,
                'borderColor' => static::ENGINE_COLORS[$engine] ?? '#6b7280',
                'backgroundColor' => static::ENGINE_COLORS[$engine] ?? '#6b7280',
                'spanGaps' => false,
                'tension' => 0.3,
                'pointRadius' => count($values) > 60 ? 0 : 3,
            ])->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => ['y' => ['min' => 0, 'max' => 100, 'ticks' => ['stepSize' => 25]]],
        ];
    }
}
