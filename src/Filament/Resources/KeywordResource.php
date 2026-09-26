<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\GeneratePromptsAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;

class KeywordResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Keyword::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 30;

    protected static ?string $recordTitleAttribute = 'keyword';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/keywords';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-key';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('keyword')->searchable()->sortable(),
                TextColumn::make('brand.name')->label('Brand')->sortable()->toggleable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('search_volume')->label('Volume')->numeric()->sortable()->placeholder('—'),
                TextColumn::make('impressions')->numeric()->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('clicks')->numeric()->sortable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_branded')->label('Branded')->boolean()->toggleable(),
                TextColumn::make('prompts_count')->label('Prompts')->counts('prompts')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('brand')->relationship('brand', 'name')->preload(),
                SelectFilter::make('source')->options(KeywordSource::class)->multiple(),
                TernaryFilter::make('is_branded')->label('Branded'),
                SelectFilter::make('status')->options(['active' => 'Active', 'ignored' => 'Ignored']),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GeneratePromptsAction::fromKeywords(),
                    BulkAction::make('ignore')
                        ->label('Ignore')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'ignored']))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('restore')
                        ->label('Use again')
                        ->icon('heroicon-o-eye')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'active']))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('search_volume', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeywords::route('/'),
        ];
    }
}
