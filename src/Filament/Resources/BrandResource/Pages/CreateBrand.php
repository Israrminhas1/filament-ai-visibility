<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\Concerns\CleansBrandSettings;

class CreateBrand extends CreateRecord
{
    use CleansBrandSettings;
    use HandlesLimitExceptions;

    protected static string $resource = BrandResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->cleanSettings($data);
    }
}
