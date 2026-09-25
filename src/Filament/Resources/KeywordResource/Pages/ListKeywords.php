<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource\Pages;

use Filament\Resources\Pages\ListRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\ImportAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource;

class ListKeywords extends ListRecords
{
    use HandlesLimitExceptions;

    protected static string $resource = KeywordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::keywords(),
        ];
    }
}
