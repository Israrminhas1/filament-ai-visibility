<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Support\CsvExport;

class ListResults extends ListRecords
{
    protected static string $resource = ResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => CsvExport::download(
                    'ai-visibility-answers-' . now()->format('Y-m-d') . '.csv',
                    ['Answered at', 'Brand', 'Prompt', 'Engine', 'Model', 'Sample', 'Status', 'Brand mentioned', 'Position', 'Mentions', 'Brand cited', 'Competitors mentioned', 'Sources', 'Cost (USD)', 'Answer'],
                    $this->exportRows(),
                )),
        ];
    }

    /**
     * Rows for the answers matching the table's current filters and search.
     */
    protected function exportRows(): \Generator
    {
        // Unsorted: lazyById() pages by ID and would be confused by another sort order.
        $query = $this->getFilteredTableQuery()
            ->with(['brand', 'prompt', 'mentions.competitor', 'citations']);

        foreach ($query->lazyById(500) as $result) {
            /** @var Result $result */
            yield [
                $result->ran_at?->toDateTimeString(),
                $result->brand?->name,
                $result->prompt?->text,
                ResultResource::engineLabel($result->engine),
                $result->model,
                $result->sample,
                $result->status->getLabel(),
                $result->brand_mentioned,
                $result->brand_position,
                $result->brand_mention_count,
                $result->brand_cited,
                $result->mentions->where('subject_type', 'competitor')->map(fn ($m) => $m->competitor?->name ?? $m->name_matched)->implode(', '),
                $result->citations->pluck('url')->implode(' '),
                $result->cost_usd,
                $result->answer,
            ];
        }
    }
}
