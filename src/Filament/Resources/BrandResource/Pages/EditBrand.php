<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\GeneratePromptsAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\RunNowAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource\Pages\ListCandidates;
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
            RunNowAction::make(fn () => $this->getRecord()),
            ListCandidates::discoverAction(fn () => $this->getRecord())->label('Discover competitors')->color('gray'),
            GeneratePromptsAction::make(fn () => $this->getRecord()),
            GeneratePromptsAction::organiseTopics(fn () => $this->getRecord()),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->cleanSettings($data);
    }
}
