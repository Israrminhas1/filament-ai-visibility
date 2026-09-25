<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Reports\PromptHistory;

class ViewPrompt extends ViewRecord
{
    protected static string $resource = PromptResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->text;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->brand?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->schema(fn (Schema $schema) => PromptResource::form($schema)),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('ai-visibility::prompts.history')
                    ->viewData(fn () => [
                        'history' => app(PromptHistory::class)->forPrompt($this->getRecord()),
                        'engineLabel' => fn (string $engine) => ResultResource::engineLabel($engine),
                        'answerUrl' => fn ($result) => AiVisibilityPlugin::pageUrl(ResultResource::class, 'view', ['record' => $result]),
                    ]),
            ]);
    }
}
