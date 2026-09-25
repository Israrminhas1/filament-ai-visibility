<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class PromptMovers extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.prompt-movers';

    protected static ?int $sort = 6;

    protected int | string | array $columnSpan = ['md' => 2, 'xl' => 2];

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();
        $prompts = $filters ? $this->metrics()->prompts($filters) : collect();

        $link = fn (array $row) => AiVisibilityPlugin::pageUrl(PromptResource::class, 'view', ['record' => $row['prompt_id']]);

        return [
            'gained' => $prompts->filter(fn ($row) => ($row['change'] ?? 0) > 0)->sortByDesc('change')->take(5)->map(fn ($row) => $row + ['url' => $link($row)]),
            'lost' => $prompts->filter(fn ($row) => ($row['change'] ?? 0) < 0)->sortBy('change')->take(5)->map(fn ($row) => $row + ['url' => $link($row)]),
            'weakest' => $prompts->sortBy('visibility')->take(5)->map(fn ($row) => $row + ['url' => $link($row)]),
        ];
    }
}
