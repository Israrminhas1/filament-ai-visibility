<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use IsrarMinhas\FilamentAiVisibility\Competitors\CandidateActions;
use IsrarMinhas\FilamentAiVisibility\Competitors\CandidateNotOpen;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Jobs\ClassifyCandidatesJob;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;

class CandidateResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Candidate::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 15;

    protected static ?string $modelLabel = 'discovered competitor';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/discovered';
    }

    public static function getNavigationLabel(): string
    {
        return 'Discovered';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-magnifying-glass-circle';
    }

    /**
     * Likely direct competitors waiting for review.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Candidate::query()
                ->where('status', Candidate::STATUS_CLASSIFIED)
                ->where('label', CompetitorLabel::DirectCompetitor)
                ->count();
        } catch (\Throwable) {
            return null;
        }

        return $count ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Classification')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('label')->badge()->placeholder('Not classified yet'),
                        TextEntry::make('confidence')->badge()->color(fn (?string $state) => ['high' => 'success', 'medium' => 'warning'][$state] ?? 'gray')->placeholder('—'),
                        TextEntry::make('latestClassification.company_name')->label('Company')->placeholder('—'),
                        TextEntry::make('domain')->url(fn (Candidate $record) => $record->domain ? 'https://' . $record->domain : null, true)->placeholder('—'),
                        TextEntry::make('latestClassification.offering_summary')->label('What it offers')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('latestClassification.reason')->label('Why this label')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('latestClassification.override_label')->label('Your correction')->badge()->placeholder('None')->visible(fn (Candidate $record) => (bool) $record->latestClassification?->override_label),
                    ]),
                Section::make('Where it appears')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('score')->suffix(' / 100'),
                        TextEntry::make('answers')->label('Answers'),
                        TextEntry::make('prompts')->label('Prompts'),
                        TextEntry::make('engines')->label('Engines')->state(fn (Candidate $record) => implode(', ', array_map(fn ($e) => ResultResource::engineLabel($e), $record->engines ?? []))),
                        TextEntry::make('avg_position')->label('Average position')->prefix('#')->placeholder('—'),
                        TextEntry::make('last_seen_at')->label('Last seen')->since(),
                    ]),
                Section::make('Evidence used')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('latestClassification.evidence.website.title')->label('Website title')->placeholder('—'),
                        TextEntry::make('latestClassification.evidence.website.description')->label('Website description')->placeholder('—'),
                        TextEntry::make('latestClassification.evidence.website.excerpt')->label('Website text')->placeholder('—'),
                        TextEntry::make('latestClassification.evidence.mentions')
                            ->label('How AI answers described it')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Candidate $record) => $record->domain && $record->domain !== $record->name ? $record->domain : null)
                    ->weight('bold'),
                TextColumn::make('brand.name')->label('Brand')->sortable()->toggleable(),
                TextColumn::make('score')->badge()->sortable()->color(fn (int $state) => $state >= 60 ? 'danger' : ($state >= 30 ? 'warning' : 'gray')),
                TextColumn::make('label')->badge()->placeholder('Not classified')->sortable(),
                TextColumn::make('confidence')->badge()->color(fn (?string $state) => ['high' => 'success', 'medium' => 'warning'][$state] ?? 'gray')->placeholder('—')->toggleable(),
                TextColumn::make('answers')->label('Answers')->sortable()
                    ->description(fn (Candidate $record) => "{$record->prompts} prompts · " . count($record->engines ?? []) . ' engines'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Candidate::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => [Candidate::STATUS_ACCEPTED => 'success', Candidate::STATUS_CLASSIFIED => 'warning', Candidate::STATUS_NEW => 'info'][$state] ?? 'gray'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Candidate::statuses())
                    ->multiple()
                    ->default([Candidate::STATUS_NEW, Candidate::STATUS_CLASSIFIED]),
                SelectFilter::make('label')->options(CompetitorLabel::class)->multiple(),
                SelectFilter::make('brand')->relationship('brand', 'name')->preload(),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label('Track')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Candidate $record) => $record->isOpen())
                    ->action(fn (Candidate $record) => static::accept($record)),
                ActionGroup::make([
                    Action::make('view')
                        ->label('Evidence')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn (Candidate $record) => $record->name)
                        ->modalContent(fn (Candidate $record) => view('ai-visibility::candidates.evidence', ['candidate' => $record->load('latestClassification')]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Action::make('relabel')
                        ->label('Change label')
                        ->icon('heroicon-o-tag')
                        ->schema([
                            Select::make('label')->options(CompetitorLabel::class)->required(),
                        ])
                        ->fillForm(fn (Candidate $record) => ['label' => $record->label])
                        ->action(function (Candidate $record, array $data) {
                            app(CandidateActions::class)->relabel($record, CompetitorLabel::from($data['label'] instanceof CompetitorLabel ? $data['label']->value : $data['label']), auth()->id());
                            Notification::make()->title('Label updated')->body('Future classifications for this brand will learn from this correction.')->success()->send();
                        }),
                    Action::make('reject')
                        ->label('Not a competitor')
                        ->icon('heroicon-o-x-mark')
                        ->visible(fn (Candidate $record) => $record->isOpen())
                        ->action(fn (Candidate $record) => app(CandidateActions::class)->reject($record)),
                    Action::make('ignore')
                        ->label('Ignore forever')
                        ->icon('heroicon-o-eye-slash')
                        ->visible(fn (Candidate $record) => $record->status !== Candidate::STATUS_IGNORED)
                        ->requiresConfirmation()
                        ->modalDescription('It will not be suggested again.')
                        ->action(fn (Candidate $record) => app(CandidateActions::class)->ignore($record)),
                    Action::make('reopen')
                        ->label('Review again')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (Candidate $record) => in_array($record->status, [Candidate::STATUS_REJECTED, Candidate::STATUS_IGNORED], true))
                        ->action(fn (Candidate $record) => app(CandidateActions::class)->reopen($record)),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('acceptSelected')
                        ->label('Track as competitors')
                        ->icon('heroicon-o-check')
                        ->action(function (Collection $records) {
                            $accepted = 0;

                            foreach ($records->filter->isOpen() as $record) {
                                try {
                                    app(CandidateActions::class)->accept($record);
                                    $accepted++;
                                } catch (LimitExceeded $e) {
                                    Notification::make()->title('Limit reached')->body($e->getMessage())->warning()->send();

                                    break;
                                } catch (CandidateNotOpen) {
                                    // Handled meanwhile, e.g. by someone else.
                                }
                            }

                            Notification::make()->title("Now tracking {$accepted} competitors")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('classifySelected')
                        ->label('Classify again')
                        ->icon('heroicon-o-sparkles')
                        ->action(function (Collection $records) {
                            // Tracked, rejected and ignored candidates keep their decision.
                            $records = $records->filter->isOpen();

                            if ($records->isEmpty()) {
                                Notification::make()->title('Nothing to classify')->body('Only candidates still to review are classified again.')->warning()->send();

                                return;
                            }

                            // Runs on the queue: classification calls the AI and can take a while.
                            foreach ($records->groupBy('brand_id') as $brandId => $candidates) {
                                ClassifyCandidatesJob::dispatch((int) $brandId, $candidates->first()->tenant_id, $candidates->map->getKey()->values()->all());
                            }

                            Notification::make()
                                ->title('Classification started in the background')
                                ->body("{$records->count()} candidates will be classified in a few minutes.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('rejectSelected')
                        ->label('Not competitors')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn (Collection $records) => $records->each(fn ($record) => app(CandidateActions::class)->reject($record)))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('ignoreSelected')
                        ->label('Ignore forever')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn ($record) => app(CandidateActions::class)->ignore($record)))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('score', 'desc')
            ->emptyStateHeading('Nothing discovered yet')
            ->emptyStateDescription('Competitors are found automatically in the answers from each run. Run tracking, or use "Discover now".');
    }

    public static function accept(Candidate $record): void
    {
        try {
            $competitor = app(CandidateActions::class)->accept($record);
        } catch (LimitExceeded $e) {
            Notification::make()->title('Limit reached')->body($e->getMessage())->warning()->persistent()->send();

            return;
        } catch (CandidateNotOpen $e) {
            Notification::make()->title('Already handled')->body($e->getMessage())->warning()->send();

            return;
        }

        Notification::make()
            ->title("Now tracking {$competitor->name}")
            ->body('Past answers were updated, so it appears in share of voice straight away.')
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCandidates::route('/'),
        ];
    }
}
