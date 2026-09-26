<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns\HasReportFilters;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

class TopicsReport extends Dashboard
{
    use HasAiVisibilityNavigation;
    use HasReportFilters;

    protected static string $routePath = '/ai-visibility/topics';

    protected static int $aiVisibilitySort = 6;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'ai-visibility/topics';
    }

    public static function getNavigationLabel(): string
    {
        return 'Topics';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-folder';
    }

    public function getTitle(): string
    {
        return 'Topics';
    }

    public function getWidgets(): array
    {
        return [Widgets\TopicPerformance::class];
    }

    public function getColumns(): int | array
    {
        return 1;
    }
}
