<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\ImportAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource;

class KeywordsRelationManager extends RelationManager
{
    use HandlesLimitExceptions;

    protected static string $relationship = 'keywords';

    public function table(Table $table): Table
    {
        return KeywordResource::table($table)
            ->headerActions([
                ImportAction::keywords(fn () => $this->getOwnerRecord()),
            ]);
    }
}
