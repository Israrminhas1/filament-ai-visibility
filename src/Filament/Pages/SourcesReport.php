<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Widgets\WidgetConfiguration;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns\HasReportFilters;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

class SourcesReport extends Dashboard
{
    use HasAiVisibilityNavigation;
    use HasReportFilters;

    protected static string $routePath = '/ai-visibility/sources';

    protected static int $aiVisibilitySort = 2;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'ai-visibility/sources';
    }

    public static function getNavigationLabel(): string
    {
        return 'Sources';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-link';
    }

    public function getTitle(): string
    {
        return 'Sources';
    }

    public function getSubheading(): ?string
    {
        return 'The sites AI engines rely on when answering your prompts. Getting featured on them is the fastest way to be recommended.';
    }

    /**
     * @return array<class-string|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            Widgets\SourceCategoriesChart::class,
            Widgets\TopSources::make(['limit' => 25]),
            Widgets\OwnPagesCited::class,
        ];
    }

    public function getColumns(): int | array
    {
        return ['md' => 2];
    }
}
