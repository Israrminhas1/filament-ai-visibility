<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;

class ListBrands extends ListRecords
{
    use HandlesLimitExceptions;

    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
