<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Filament\Actions\RunNowAction;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

class BrandResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Brand::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/brands';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-building-storefront';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('brand')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Profile')
                            ->icon('heroicon-o-identification')
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')->required()->maxLength(255),
                                TagsInput::make('domains')
                                    ->label('Websites')
                                    ->placeholder('acme.com')
                                    ->helperText('The first one is the main website. Subdomains count automatically.')
                                    ->required(),
                                Textarea::make('description')
                                    ->label('What the brand offers')
                                    ->rows(3)
                                    ->columnSpanFull(),
                                TextInput::make('industry')->placeholder('CRM software'),
                                TextInput::make('market')->placeholder('United Kingdom'),
                                Select::make('run_frequency')
                                    ->label('Run frequency')
                                    ->options(RunFrequency::class)
                                    ->default(fn () => app(Settings::class)->get('runs.frequency', 'weekly'))
                                    ->required(),
                                Toggle::make('is_active')
                                    ->label('Tracking on')
                                    ->default(true)
                                    ->inline(false),
                            ]),

                        Tab::make('Detection')
                            ->icon('heroicon-o-magnifying-glass')
                            ->schema([
                                TagsInput::make('aliases')
                                    ->label('Other names')
                                    ->placeholder('Acme Inc')
                                    ->helperText('Other spellings, abbreviations and product names that count as a mention.'),
                                TagsInput::make('exclusions')
                                    ->label('Ignore these phrases')
                                    ->placeholder('the notion of')
                                    ->helperText('Mentions inside these phrases are not counted. Useful when the brand name is a common word.'),
                            ]),

                        Tab::make('Settings')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                Section::make('Overrides for this brand')
                                    ->description('Leave a field empty to use the global setting (shown as the placeholder).')
                                    ->columns(2)
                                    ->schema([
                                        CheckboxList::make('settings.engines.enabled')
                                            ->label('Engines')
                                            ->options(fn () => app(EngineRegistry::class)->options())
                                            ->helperText(fn () => 'Global: ' . (implode(', ', app(Settings::class)->get('engines.enabled', [])) ?: 'none'))
                                            ->columnSpanFull(),
                                        TextInput::make('settings.runs.samples')
                                            ->label('Samples per prompt')
                                            ->numeric()->minValue(1)->maxValue(5)
                                            ->placeholder(fn () => (string) app(Settings::class)->get('runs.samples')),
                                        TextInput::make('settings.limits.max_active_prompts_per_brand')
                                            ->label('Max active prompts')
                                            ->numeric()->minValue(1)
                                            ->placeholder(fn () => (string) (app(Settings::class)->get('limits.max_active_prompts_per_brand') ?: 'No limit')),
                                        TextInput::make('settings.limits.max_competitors_per_brand')
                                            ->label('Max competitors')
                                            ->numeric()->minValue(1)
                                            ->placeholder(fn () => (string) (app(Settings::class)->get('limits.max_competitors_per_brand') ?: 'No limit')),
                                        TextInput::make('settings.limits.max_runs_per_brand_per_day')
                                            ->label('Max runs per day')
                                            ->numeric()->minValue(1)
                                            ->placeholder(fn () => (string) (app(Settings::class)->get('limits.max_runs_per_brand_per_day') ?: 'No limit')),
                                        TextInput::make('settings.budget.monthly_usd')
                                            ->label('Monthly budget for this brand (USD)')
                                            ->numeric()->minValue(0)->prefix('$')
                                            ->placeholder('Uses the global budget only'),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Brand $record) => $record->primaryDomain()),
                TextColumn::make('competitors_count')->label('Competitors')->counts('competitors')->sortable(),
                TextColumn::make('active_prompts_count')
                    ->label('Active prompts')
                    ->counts('activePrompts')
                    ->sortable(),
                TextColumn::make('keywords_count')->label('Keywords')->counts('keywords')->sortable()->toggleable(),
                TextColumn::make('run_frequency')->label('Runs')->badge(),
                ToggleColumn::make('is_active')->label('Tracking'),
                TextColumn::make('last_run_at')->label('Last run')->since()->placeholder('Never')->sortable(),
            ])
            ->recordActions([
                RunNowAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CompetitorsRelationManager::class,
            RelationManagers\PromptsRelationManager::class,
            RelationManagers\TopicsRelationManager::class,
            RelationManagers\KeywordsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
