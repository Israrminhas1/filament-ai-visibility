<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryUnanswered')
                ->label('Retry unanswered')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Run $record) => $record->isFinished() && $record->results()->whereIn('status', [ResultStatus::Skipped, ResultStatus::Failed])->exists())
                ->requiresConfirmation()
                ->modalDescription('Ask the skipped and failed prompts again in real time, e.g. now that a paused engine is back. Engines that are still paused are skipped again.')
                ->action(function (Run $record) {
                    $count = app(RunPlanner::class)->retryUnanswered($record);

                    Notification::make()->title('Retrying ' . Text::count($count, 'answer'))->success()->send();
                }),
        ];
    }
}
