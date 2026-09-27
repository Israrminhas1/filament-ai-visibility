<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource;

class ManageConnections extends ManageRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Connect source')
                ->authorize(fn () => ConnectionResource::canCreate())
                ->using(function (array $data) {
                    $connection = ConnectionResource::save($data);

                    ConnectionResource::testConnection($connection, 'Connected', 'Saved, but the test failed');

                    return $connection;
                })
                ->successNotification(null),
        ];
    }
}
