<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns\HasReportFilters;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;

class HeadToHeadReport extends Dashboard
{
    use HasAiVisibilityNavigation;
    use HasReportFilters;

    protected static string $routePath = '/ai-visibility/head-to-head';

    protected static int $aiVisibilitySort = 4;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'ai-visibility/head-to-head';
    }

    public static function getNavigationLabel(): string
    {
        return 'Head-to-head';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-scale';
    }

    public function getTitle(): string
    {
        return 'Head-to-head';
    }

    protected function extraFilterComponents(): array
    {
        return [
            Select::make('competitor')
                ->options(fn (Get $get) => Competitor::query()
                    ->where('brand_id', $get('brand') ?: Brand::query()->orderBy('name')->value('id'))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->placeholder('First competitor'),
        ];
    }

    public function getWidgets(): array
    {
        return [Widgets\HeadToHead::class];
    }

    public function getColumns(): int | array
    {
        return 1;
    }
}
