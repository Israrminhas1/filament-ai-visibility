<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns\HasReportFilters;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

class CompetitorsReport extends Dashboard
{
    use HasAiVisibilityNavigation;
    use HasReportFilters;

    protected static string $routePath = '/ai-visibility/competitors';

    protected static int $aiVisibilitySort = 3;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'ai-visibility/competitors';
    }

    public static function getNavigationLabel(): string
    {
        return 'Competitors';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-trophy';
    }

    public function getTitle(): string
    {
        return 'Competitors';
    }

    public function getWidgets(): array
    {
        return [
            Widgets\CompetitorLeaderboard::class,
            Widgets\EngineHeatmap::class,
            Widgets\BrandPerception::class,
        ];
    }

    public function getColumns(): int | array
    {
        return 1;
    }
}
