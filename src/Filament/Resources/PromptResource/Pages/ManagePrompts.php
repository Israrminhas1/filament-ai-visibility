<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\GeneratePromptsAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\ImportAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Support\CsvExport;

class ManagePrompts extends ManageRecords
{
    use HandlesLimitExceptions;

    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => CsvExport::download(
                    'ai-visibility-prompts-' . now()->format('Y-m-d') . '.csv',
                    ['Brand', 'Prompt', 'Topic', 'Intent', 'Status', 'Answers (30 days)', 'Brand mentioned (30 days)', 'Visibility % (30 days)', 'Last run'],
                    $this->exportRows(),
                )),
            GeneratePromptsAction::make(),
            ImportAction::prompts(),
            CreateAction::make()->label('New prompt'),
        ];
    }

    protected function exportRows(): \Generator
    {
        $query = $this->getFilteredTableQuery()->with(['brand', 'topic'])->withVisibility();

        foreach ($query->lazyById(500) as $prompt) {
            /** @var Prompt $prompt */
            yield [
                $prompt->brand?->name,
                $prompt->text,
                $prompt->topic?->name,
                $prompt->intent->getLabel(),
                $prompt->status->getLabel(),
                $prompt->answers_30d,
                $prompt->mentioned_30d,
                $prompt->answers_30d ? round($prompt->mentioned_30d / $prompt->answers_30d * 100, 1) : null,
                $prompt->last_run_at?->toDateTimeString(),
            ];
        }
    }
}
