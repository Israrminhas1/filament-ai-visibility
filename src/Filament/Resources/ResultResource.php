<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\Recommendation;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\Sentiment;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
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

    /**
     * One short verdict for an answer: "#2 · cited", "Mentioned", "Not mentioned", "Failed"…
     */
    public static function verdict(Result $result): string
    {
        if ($result->status !== ResultStatus::Success) {
            return $result->status->getLabel();
        }

        $label = match (true) {
            $result->brand_mentioned && $result->brand_position => '#' . $result->brand_position,
            $result->brand_mentioned => 'Mentioned',
            $result->brand_cited => 'Not named',
            default => 'Not mentioned',
        };

        return $result->brand_cited ? $label . ' · cited' : $label;
    }

    public static function verdictColor(Result $result): string
    {
        if ($result->status !== ResultStatus::Success) {
            return $result->status->getColor();
        }

        return match (true) {
            $result->brand_mentioned => 'success',
            $result->brand_cited => 'info',
            default => 'gray',
        };
    }

    /**
     * Markdown-free text for short snippets: no "##", "**", links or list markers.
     */
    public static function plainText(?string $text): string
    {
        $text = (string) $text;
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/^\s{0,3}(#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+)/mu', '', $text);
        $text = (string) preg_replace('/(\*\*|__|~~|`+)/u', '', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])[*_](\S[^*_]*?)[*_](?![\p{L}\p{N}])/u', '$1', $text);
        $text = (string) preg_replace('/(^|\s)#{1,6}\s+/u', '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Everyone named in the answer: the brand first, then competitors, then other names.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mentionRows(Result $result): array
    {
        $order = ['brand' => 0, 'competitor' => 1];

        return $result->mentions
            ->each(fn (ResultMention $mention) => $mention->setRelation('result', $result))
            ->sortBy([
                fn (ResultMention $a, ResultMention $b) => ($order[$a->subject_type] ?? 2) <=> ($order[$b->subject_type] ?? 2),
                fn (ResultMention $a, ResultMention $b) => $a->position <=> $b->position,
            ])
            ->map(fn (ResultMention $mention) => [
                'type' => $mention->subject_type,
                'name' => $mention->subject_type === 'entity' ? $mention->name_matched : $mention->label(),
                'position' => $mention->position,
                'count' => (int) $mention->count,
                'snippet' => filled($mention->snippet) ? static::plainText($mention->snippet) : null,
                'sentiment' => Sentiment::tryFrom((string) $mention->sentiment),
                'recommendation' => Recommendation::tryFrom((string) $mention->recommendation)?->getLabel(),
                'descriptors' => array_values(array_filter((array) ($mention->descriptors ?? []))),
            ])
            ->values()
            ->all();
    }

    /**
     * The answer's sources, split into those cited in the text and those only read.
     *
     * @return array{cited: array<int, array<string, mixed>>, read: array<int, array<string, mixed>>}
     */
    public static function sourceRows(Result $result): array
    {
        $answer = mb_strtolower((string) $result->answer);
        $rows = ['cited' => [], 'read' => []];

        foreach ($result->citations as $citation) {
            $inText = $answer !== '' && (str_contains($answer, mb_strtolower($citation->url)) || str_contains($answer, mb_strtolower($citation->domain)));

            $rows[$inText ? 'cited' : 'read'][] = [
                'position' => $citation->position,
                'url' => preg_match('#^https?://#i', (string) $citation->url) ? $citation->url : null,
                'domain' => $citation->domain,
                'title' => filled($citation->title) ? static::plainText($citation->title) : null,
                'badge' => $citation->is_brand ? 'Your site' : ($citation->competitor_id ? 'Competitor' . ($citation->competitor ? ': ' . $citation->competitor->name : '') : null),
                'color' => $citation->is_brand ? 'success' : 'danger',
            ];
        }

        return $rows;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->compact()
                    ->columns(['default' => 2, 'md' => 4])
                    ->schema([
                        TextEntry::make('brand_mentioned')
                            ->label('Mentioned')
                            ->badge()
                            ->state(fn (Result $record) => $record->status === ResultStatus::Success ? ($record->brand_mentioned ? 'Yes' : 'No') : '—')
                            ->icon(fn (string $state) => match ($state) {
                                'Yes' => 'heroicon-m-check-circle',
                                'No' => 'heroicon-m-x-circle',
                                default => null,
                            })
                            ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'gray')
                            ->helperText(fn (Result $record) => $record->brand_mention_count > 1 ? "Named {$record->brand_mention_count} times" : null),
                        TextEntry::make('brand_position')
                            ->label('Position')
                            ->state(fn (Result $record) => $record->brand_position ? '#' . $record->brand_position : null)
                            ->placeholder('—')
                            ->helperText('Order among the brands named')
                            ->weight('bold'),
                        TextEntry::make('brand_cited')
                            ->label('Cited')
                            ->badge()
                            ->state(fn (Result $record) => $record->status === ResultStatus::Success ? ($record->brand_cited ? 'Yes' : 'No') : '—')
                            ->icon(fn (string $state) => match ($state) {
                                'Yes' => 'heroicon-m-link',
                                'No' => 'heroicon-m-x-circle',
                                default => null,
                            })
                            ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'gray')
                            ->helperText('Your site is one of the sources'),
                        TextEntry::make('brand_sentiment')
                            ->label('Sentiment')
                            ->badge()
                            ->state(fn (Result $record) => Sentiment::tryFrom((string) $record->brand_sentiment)?->getLabel())
                            ->color(fn (Result $record) => Sentiment::tryFrom((string) $record->brand_sentiment)?->getColor() ?? 'gray')
                            ->helperText(fn (Result $record) => Recommendation::tryFrom((string) $record->brand_recommendation)?->getLabel())
                            ->placeholder('Not analysed'),
                    ]),

                Section::make('Answer')
                    ->description(fn () => new HtmlString(view('ai-visibility::results.legend')->render()))
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->schema([
                        View::make('ai-visibility::results.answer')
                            ->viewData(fn (Result $record) => [
                                'html' => filled($record->answer) ? app(AnswerHighlighter::class)->html($record) : null,
                                'empty' => match ($record->status) {
                                    ResultStatus::Skipped => 'Skipped: ' . ($record->skip_reason ?: 'no reason recorded.'),
                                    ResultStatus::Failed => 'Failed: ' . ($record->error ?: 'no error recorded.'),
                                    default => 'No answer yet.',
                                },
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->schema([
                        Section::make('Who was mentioned')
                            ->compact()
                            ->schema([
                                View::make('ai-visibility::results.mentions')
                                    ->viewData(fn (Result $record) => ['mentions' => static::mentionRows($record)]),
                            ]),

                        Section::make('Sources')
                            ->compact()
                            ->schema([
                                View::make('ai-visibility::results.sources')
                                    ->viewData(fn (Result $record) => static::sourceRows($record)),
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
        return static::answersTable($table);
    }

    /**
     * The answers table. Inside a run the brand is already known, so it is not repeated.
     */
    public static function answersTable(Table $table, bool $inRun = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'prompt',
                'brand',
                'mentions' => fn ($query) => $query->where('subject_type', 'competitor')->with('competitor'),
            ]))
            ->columns([
                TextColumn::make('prompt.text')
                    ->label('Prompt')
                    ->wrap()
                    ->lineClamp(2)
                    ->description(fn (Result $record) => $inRun ? null : $record->brand?->name)
                    ->searchable(),
                TextColumn::make('engine')
                    ->formatStateUsing(fn (string $state) => static::engineLabel($state))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('result')
                    ->label('Result')
                    ->badge()
                    ->state(fn (Result $record) => static::verdict($record))
                    ->color(fn (Result $record) => static::verdictColor($record))
                    ->tooltip(fn (Result $record) => match ($record->status) {
                        ResultStatus::Skipped => $record->skip_reason,
                        ResultStatus::Failed => $record->error,
                        default => null,
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy('brand_mentioned', $direction === 'asc' ? 'desc' : 'asc')
                        ->orderBy('brand_position', $direction)),
                TextColumn::make('competitors')
                    ->label('Competitors mentioned')
                    ->badge()
                    ->color(fn (string $state) => str_starts_with($state, '+') ? 'gray' : 'warning')
                    ->state(function (Result $record) {
                        $names = $record->mentions
                            ->where('subject_type', 'competitor')
                            ->map(fn (ResultMention $mention) => $mention->competitor?->name ?? $mention->name_matched)
                            ->unique()
                            ->values();

                        if ($names->isEmpty()) {
                            return null;
                        }

                        return $names->count() > 3
                            ? [...$names->take(3)->all(), '+' . ($names->count() - 3)]
                            : $names->all();
                    })
                    ->placeholder('—'),
                TextColumn::make('brand_sentiment')
                    ->label('Sentiment')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Sentiment::tryFrom((string) $state)?->getLabel() ?? $state)
                    ->color(fn (?string $state) => Sentiment::tryFrom((string) $state)?->getColor() ?? 'gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('citations_count')
                    ->label('Sources')
                    ->counts('citations')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ran_at')
                    ->label('Answered')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('model')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('skip_reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->money('usd', 4)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('brand')
                    ->relationship('brand', 'name')
                    ->preload()
                    ->visible(! $inRun),
                SelectFilter::make('engine')
                    ->options(fn () => app(EngineRegistry::class)->options()),
                SelectFilter::make('prompt')
                    ->relationship('prompt', 'text')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options(ResultStatus::class),
                TernaryFilter::make('brand_mentioned')->label('Brand mentioned'),
                TernaryFilter::make('brand_cited')->label('Brand cited'),
                SelectFilter::make('competitor')
                    ->label('Competitor mentioned')
                    ->searchable()
                    ->options(fn () => static::competitorOptions())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $query->whereHas('mentions', fn ($mentions) => $mentions
                            ->where('subject_type', 'competitor')
                            ->where('subject_id', $data['value'])),
                    )),
                SelectFilter::make('brand_sentiment')
                    ->label('Sentiment')
                    ->options(Sentiment::class)
                    // Only offered once answers have been analysed.
                    ->visible(fn () => Result::query()->whereNotNull('brand_sentiment')->exists()),
                Filter::make('answered')
                    ->schema([
                        DatePicker::make('from')->label('Answered from'),
                        DatePicker::make('until')->label('Answered until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->where('ran_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->where('ran_at', '<=', Carbon::parse($date)->endOfDay())))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? 'From ' . Carbon::parse($data['from'])->toFormattedDateString() : null,
                        ($data['until'] ?? null) ? 'Until ' . Carbon::parse($data['until'])->toFormattedDateString() : null,
                    ]))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    /**
     * Competitors to filter by, with the brand name when there is more than one brand.
     *
     * @return array<int, string>
     */
    protected static function competitorOptions(): array
    {
        $competitors = Competitor::query()->with('brand')->orderBy('name')->get();
        $manyBrands = $competitors->pluck('brand_id')->unique()->count() > 1;

        return $competitors
            ->mapWithKeys(fn (Competitor $competitor) => [$competitor->getKey() => $competitor->name . ($manyBrands && $competitor->brand ? " ({$competitor->brand->name})" : '')])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResults::route('/'),
            'view' => Pages\ViewResult::route('/{record}'),
        ];
    }
}
