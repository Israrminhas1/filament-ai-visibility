<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertEventResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertEventResource;
use IsrarMinhas\FilamentAiVisibility\Models\AlertEvent;

class ListAlertEvents extends ListRecords
{
    protected static string $resource = AlertEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label('Mark all read')
                ->icon('heroicon-o-check')
                ->color('gray')
                ->visible(fn () => AlertEvent::query()->whereNull('read_at')->exists())
                ->action(fn () => AlertEvent::query()->whereNull('read_at')->update(['read_at' => now()])),
        ];
    }
}
