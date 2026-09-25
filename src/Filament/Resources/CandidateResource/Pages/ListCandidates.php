<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;

class ListCandidates extends ListRecords
{
    use HandlesLimitExceptions;

    protected static string $resource = CandidateResource::class;

    public function getSubheading(): ?string
    {
        return 'Companies and sites the AI engines mention alongside you, ranked by how often and where they appear. Track the real competitors; the classifier learns from your corrections.';
    }

    protected function getHeaderActions(): array
    {
        return [
            static::discoverAction(),
        ];
    }

    public static function discoverAction(?\Closure $brand = null): Action
    {
        return Action::make('discover')
            ->label('Discover now')
            ->icon('heroicon-o-sparkles')
            ->schema($brand ? [] : [
                Select::make('brand_id')->label('Brand')->options(PromptTable::brandOptions())->required(),
            ])
            ->modalDescription('Finds names and sites in recent answers, scores them, and classifies the top ones with your AI helper engine. This uses a few AI calls.')
            ->action(function (array $data) use ($brand) {
                $target = $brand ? $brand() : Brand::query()->findOrFail($data['brand_id']);
                $report = app(CompetitorIntelligence::class)->run($target);

                Notification::make()
                    ->title("{$report['candidates']} candidates found, {$report['classified']} classified")
                    ->body($report['skipped'])
                    ->status($report['skipped'] ? 'warning' : 'success')
                    ->send();
            });
    }
}
