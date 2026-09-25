<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retrySkipped')
                ->label('Retry skipped')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Run $record) => $record->isFinished() && $record->results()->where('status', ResultStatus::Skipped)->exists())
                ->requiresConfirmation()
                ->modalDescription('Queue the skipped answers again, e.g. now that a paused engine is back. Paused engines are skipped again.')
                ->action(function (Run $record) {
                    $count = app(RunPlanner::class)->retrySkipped($record);

                    Notification::make()->title("Retrying {$count} answers")->success()->send();
                }),
        ];
    }
}
