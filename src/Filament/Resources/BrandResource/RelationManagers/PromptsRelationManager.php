<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\ImportAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Filament\Forms\PromptForm;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;

class PromptsRelationManager extends RelationManager
{
    use HandlesLimitExceptions;

    protected static string $relationship = 'prompts';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(PromptForm::fields(fn () => $this->getOwnerRecord()->getKey()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('text')
            ->description(function () {
                $brand = $this->getOwnerRecord();
                $max = app(Limits::class)->maxActivePrompts($brand);
                $active = $brand->activePrompts()->count();

                return $max ? "{$active} of {$max} active prompts used." : "{$active} active prompts.";
            })
            ->columns(PromptTable::columns())
            ->filters(PromptTable::filters())
            ->headerActions([
                ImportAction::prompts(fn () => $this->getOwnerRecord()),
                CreateAction::make()->label('New prompt'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                PromptTable::bulkActions(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
