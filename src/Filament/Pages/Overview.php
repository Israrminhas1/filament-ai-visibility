<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns\HasReportFilters;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

class Overview extends Dashboard
{
    use HasAiVisibilityNavigation;
    use HasReportFilters;

    protected static string $routePath = '/ai-visibility';

    protected static int $aiVisibilitySort = 1;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'ai-visibility';
    }

    public static function getNavigationLabel(): string
    {
        return 'Overview';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-presentation-chart-line';
    }

    public function getTitle(): string
    {
        return 'AI visibility';
    }

    public function getWidgets(): array
    {
        return [
            Widgets\PausedEngines::class,
            Widgets\VisibilityStats::class,
            Widgets\VisibilityTrendChart::class,
            Widgets\ShareOfVoiceChart::class,
            Widgets\EngineVisibilityChart::class,
            Widgets\TopSources::class,
            Widgets\PromptMovers::class,
        ];
    }

    public function getColumns(): int | array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
