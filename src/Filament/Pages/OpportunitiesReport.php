<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns\HasReportFilters;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

class OpportunitiesReport extends Dashboard
{
    use HasAiVisibilityNavigation;
    use HasReportFilters;

    protected static string $routePath = '/ai-visibility/opportunities';

    protected static int $aiVisibilitySort = 5;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'ai-visibility/opportunities';
    }

    public static function getNavigationLabel(): string
    {
        return 'Opportunities';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-light-bulb';
    }

    public function getTitle(): string
    {
        return 'Opportunities';
    }

    public function getSubheading(): ?string
    {
        return 'Where competitors are recommended and you are not, and the sites that would put you there.';
    }

    public function getWidgets(): array
    {
        return [Widgets\Opportunities::class];
    }

    public function getColumns(): int | array
    {
        return 1;
    }
}
