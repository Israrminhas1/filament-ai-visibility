<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;

class EngineHeatmap extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.engine-heatmap';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();
        $heatmap = $filters ? app(CompetitorMetrics::class)->heatmap($filters) : ['engines' => [], 'rows' => collect()];

        return $heatmap + [
            'engineLabels' => collect($heatmap['engines'])->mapWithKeys(fn ($engine) => [$engine => ResultResource::engineLabel($engine)])->all(),
        ];
    }

    /**
     * Background for a visibility % cell: from transparent to solid green.
     */
    public static function cellStyle(?float $value): string
    {
        if ($value === null) {
            return 'opacity: 0.4;';
        }

        $alpha = round(0.08 + ($value / 100) * 0.62, 2);

        return "background: rgba(16, 185, 129, {$alpha}); font-weight: " . ($value >= 50 ? 600 : 400) . ';';
    }
}
