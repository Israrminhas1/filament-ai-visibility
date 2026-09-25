<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Forms\PromptForm;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;

class PromptResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Prompt::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 20;

    protected static ?string $recordTitleAttribute = 'text';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/prompts';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(PromptForm::fields(fn (Get $get) => $get('brand_id'), withBrandSelect: true));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['brand', 'topic'])->withVisibility())
            ->columns(PromptTable::columns(withBrand: true))
            ->filters(PromptTable::filters(withBrand: true))
            ->recordActions([
                ViewAction::make()->label('History'),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                PromptTable::bulkActions(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePrompts::route('/'),
            'view' => Pages\ViewPrompt::route('/{record}'),
        ];
    }
}
