<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;

class Opportunities extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.opportunities';

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();
        $data = $filters ? app(CompetitorMetrics::class)->opportunities($filters) : ['prompts' => collect(), 'sources' => collect(), 'missed_answers' => 0];

        return [
            'prompts' => $data['prompts']->take(25),
            'sources' => $data['sources']->map(fn ($row) => $row + [
                'category_label' => SourceCategory::label($row['category']),
                'color' => SourceCategory::color($row['category']),
            ]),
            'missed' => $data['missed_answers'],
            'promptUrl' => fn (int $id) => AiVisibilityPlugin::pageUrl(PromptResource::class, 'view', ['record' => $id]),
            'engineLabel' => fn (string $engine) => ResultResource::engineLabel($engine),
        ];
    }
}
