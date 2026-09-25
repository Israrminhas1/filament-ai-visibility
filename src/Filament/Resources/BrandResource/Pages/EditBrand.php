<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\Concerns\CleansBrandSettings;

class EditBrand extends EditRecord
{
    use CleansBrandSettings;
    use HandlesLimitExceptions;

    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->cleanSettings($data);
    }
}
