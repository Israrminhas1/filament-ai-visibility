<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;

class CompetitorsRelationManager extends RelationManager
{
    use HandlesLimitExceptions;

    protected static string $relationship = 'competitors';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TagsInput::make('domains')->label('Websites')->placeholder('competitor.com'),
                TagsInput::make('aliases')->label('Other names')->placeholder('Competitor Inc'),
                TagsInput::make('exclusions')->label('Ignore these phrases'),
                ColorPicker::make('color')->label('Chart colour'),
                Toggle::make('is_active')->label('Track')->default(true)->inline(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Competitor $record) => $record->domains[0] ?? null),
                TextColumn::make('aliases')->label('Other names')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('source')->badge()->toggleable(),
                ToggleColumn::make('is_active')->label('Track'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
