<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;

class ViewResult extends ViewRecord
{
    protected static string $resource = ResultResource::class;

    public function getTitle(): string
    {
        return 'Answer';
    }
}
