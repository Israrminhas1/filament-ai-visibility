<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages\Concerns;

use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;

/**
 * Brand / period / engine / topic filters shared by the report pages.
 */
trait HasReportFilters
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 2, 'xl' => 4])
            ->components([
                Select::make('brand')
                    ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(fn () => Brand::query()->orderBy('name')->value('name') ?? 'No brands yet')
                    ->live(),
                Select::make('period')
                    ->options(ReportFilters::periods())
                    ->default(30)
                    ->selectablePlaceholder(false),
                Select::make('engine')
                    ->options(fn () => app(EngineRegistry::class)->options())
                    ->placeholder('All engines'),
                Select::make('topic')
                    ->options(fn (Get $get) => Topic::query()
                        ->where('brand_id', $get('brand') ?: Brand::query()->orderBy('name')->value('id'))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->placeholder('All topics'),
                ...$this->extraFilterComponents(),
            ]);
    }

    /**
     * Additional filters for a specific report.
     *
     * @return array<\Filament\Forms\Components\Field>
     */
    protected function extraFilterComponents(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        if (! Brand::query()->exists()) {
            return $schema->components([
                View::make('ai-visibility::reports.empty'),
            ]);
        }

        return parent::content($schema);
    }
}
