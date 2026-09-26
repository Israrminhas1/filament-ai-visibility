<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry;

class ManageConnections extends ManageRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Connect source')
                ->using(function (array $data) {
                    $connection = ConnectionResource::save($data);
                    $result = app(KeywordSourceRegistry::class)->get($connection->type)->test($connection);

                    Notification::make()
                        ->title($result->ok ? 'Connected' : 'Saved, but the test failed')
                        ->body($result->message)
                        ->status($result->ok ? 'success' : 'warning')
                        ->send();

                    return $connection;
                })
                ->successNotification(null),
        ];
    }
}
