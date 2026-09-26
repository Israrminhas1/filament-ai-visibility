<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Jobs\DiscoverCompetitorsJob;
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

                // Runs on the queue; one already waiting for this brand covers this request too.
                if (! DiscoverCompetitorsJob::queueFor($target->getKey(), $target->tenant_id)) {
                    Notification::make()
                        ->title('Discovery is already queued for this brand')
                        ->body('Results appear when it has run.')
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Discovery started in the background')
                    ->body('Results appear in a few minutes.')
                    ->success()
                    ->send();
            });
    }
}
