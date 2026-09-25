<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\Recommendation;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\Sentiment;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Support\AnswerHighlighter;

class ResultResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Result::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 50;

    protected static ?string $modelLabel = 'answer';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/answers';
    }

    public static function getNavigationLabel(): string
    {
        return 'Answers';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-bottom-center-text';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function engineLabel(?string $engine): string
    {
        $registry = app(EngineRegistry::class);

        return $engine && $registry->has($engine) ? $registry->get($engine)->label() : (string) $engine;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(fn (Result $record) => $record->prompt?->text)
                    ->description(fn (Result $record) => static::engineLabel($record->engine) . ' · ' . $record->model . ' · ' . $record->ran_at?->toDayDateTimeString())
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('answer')
                            ->hiddenLabel()
                            ->state(fn (Result $record) => new HtmlString(app(AnswerHighlighter::class)->html($record)))
                            ->placeholder(fn (Result $record) => $record->status === ResultStatus::Skipped ? 'Skipped: ' . $record->skip_reason : ($record->error ?? 'No answer yet.')),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Visibility')
                            ->compact()
                            ->columns(2)
                            ->schema([
                                IconEntry::make('brand_mentioned')->label('Mentioned')->boolean(),
                                IconEntry::make('brand_cited')->label('Cited')->boolean(),
                                TextEntry::make('brand_position')->label('Position')->placeholder('—')->prefix('#'),
                                TextEntry::make('brand_mention_count')->label('Mentions'),
                            ]),

                        Section::make('Mentions')
                            ->compact()
                            ->schema([
                                RepeatableEntry::make('mentions')
                                    ->hiddenLabel()
                                    ->contained(false)
                                    ->schema([
                                        TextEntry::make('name')
                                            ->hiddenLabel()
                                            ->state(fn ($record) => "#{$record->position} {$record->label()}" . ($record->subject_type === 'brand' ? ' (you)' : ''))
                                            ->weight(fn ($record) => $record->subject_type === 'brand' ? 'bold' : null)
                                            ->helperText(fn ($record) => $record->snippet),
                                        TextEntry::make('analysis')
                                            ->hiddenLabel()
                                            ->badge()
                                            ->state(fn ($record) => array_values(array_filter([
                                                Sentiment::tryFrom((string) $record->sentiment)?->getLabel(),
                                                Recommendation::tryFrom((string) $record->recommendation)?->getLabel(),
                                                ...($record->descriptors ?? []),
                                            ])))
                                            ->color(fn (string $state) => match ($state) {
                                                'Positive', 'Top pick' => 'success',
                                                'Negative', 'Cautioned against' => 'danger',
                                                'Recommended' => 'info',
                                                default => 'gray',
                                            })
                                            ->visible(fn ($record) => filled($record->sentiment) || filled($record->recommendation)),
                                    ])
                                    ->placeholder('No tracked brands mentioned.'),
                            ]),

                        Section::make('Sources')
                            ->compact()
                            ->schema([
                                RepeatableEntry::make('citations')
                                    ->hiddenLabel()
                                    ->contained(false)
                                    ->schema([
                                        TextEntry::make('domain')
                                            ->hiddenLabel()
                                            ->url(fn ($record) => $record->url, shouldOpenInNewTab: true)
                                            ->badge(fn ($record) => $record->is_brand || $record->competitor_id)
                                            ->color(fn ($record) => $record->is_brand ? 'success' : ($record->competitor_id ? 'danger' : null))
                                            ->helperText(fn ($record) => $record->title),
                                    ])
                                    ->placeholder('No sources.'),
                            ]),

                        Section::make('Cost')
                            ->compact()
                            ->collapsed()
                            ->columns(2)
                            ->schema([
                                TextEntry::make('cost_usd')->label('Cost')->money('usd', 5)->placeholder('—'),
                                TextEntry::make('searches')->placeholder('—'),
                                TextEntry::make('input_tokens')->label('Input tokens')->numeric()->placeholder('—'),
                                TextEntry::make('output_tokens')->label('Output tokens')->numeric()->placeholder('—'),
                                TextEntry::make('duration_ms')->label('Time')->suffix(' ms')->placeholder('—'),
                                TextEntry::make('attempts'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['prompt', 'brand']))
            ->columns([
                TextColumn::make('prompt.text')->label('Prompt')->wrap()->lineClamp(2)->searchable(),
                TextColumn::make('engine')->formatStateUsing(fn (string $state) => static::engineLabel($state))->badge()->color('gray')->sortable(),
                IconColumn::make('brand_mentioned')->label('Mentioned')->boolean()->sortable(),
                TextColumn::make('brand_position')->label('Position')->prefix('#')->placeholder('—')->sortable(),
                IconColumn::make('brand_cited')->label('Cited')->boolean()->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('skip_reason')->label('Reason')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_usd')->label('Cost')->money('usd', 4)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ran_at')->label('Answered')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('brand')->relationship('brand', 'name')->preload(),
                SelectFilter::make('engine')->options(fn () => app(EngineRegistry::class)->options()),
                SelectFilter::make('status')->options(ResultStatus::class),
                TernaryFilter::make('brand_mentioned')->label('Brand mentioned'),
                TernaryFilter::make('brand_cited')->label('Brand cited'),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResults::route('/'),
            'view' => Pages\ViewResult::route('/{record}'),
        ];
    }
}
