<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;

class HeadToHead extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.head-to-head';

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();

        $competitor = $filters
            ? (filled($this->pageFilters['competitor'] ?? null)
                ? $filters->brand->competitors()->find($this->pageFilters['competitor'])
                : $filters->brand->competitors()->where('is_active', true)->orderBy('name')->first())
            : null;

        return [
            'competitor' => $competitor,
            'data' => $filters && $competitor instanceof Competitor ? app(CompetitorMetrics::class)->headToHead($filters, $competitor) : null,
            'promptUrl' => fn (int $id) => AiVisibilityPlugin::pageUrl(PromptResource::class, 'view', ['record' => $id]),
        ];
    }
}
