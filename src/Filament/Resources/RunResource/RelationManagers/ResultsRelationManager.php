<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';

    protected static ?string $title = 'Answers';

    public function table(Table $table): Table
    {
        return ResultResource::table($table)
            ->poll('10s')
            ->recordActions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Result $record) => AiVisibilityPlugin::pageUrl(ResultResource::class, 'view', ['record' => $record])),
            ]);
    }
}
