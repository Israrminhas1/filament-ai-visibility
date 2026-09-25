<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\ImportAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;

class ManagePrompts extends ManageRecords
{
    use HandlesLimitExceptions;

    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::prompts(),
            CreateAction::make()->label('New prompt'),
        ];
    }
}
