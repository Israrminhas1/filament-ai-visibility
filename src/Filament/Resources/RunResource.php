<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\RelationManagers\ResultsRelationManager;
use IsrarMinhas\FilamentAiVisibility\Models\Batch;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;

class RunResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Run::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 40;

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/runs';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-play-circle';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('brand.name')->label('Brand'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('trigger')->badge(),
                        TextEntry::make('progress')
                            ->state(fn (Run $record) => "{$record->processed()} / {$record->results_total} ({$record->progress()}%)"),
                        TextEntry::make('results_done')->label('Answers collected'),
                        TextEntry::make('results_skipped')->label('Skipped'),
                        TextEntry::make('results_failed')->label('Failed'),
                        TextEntry::make('cost_usd')->label('Cost')->money('usd', 4),
                        TextEntry::make('engines')
                            ->label('Engines')
                            ->state(fn (Run $record) => implode(', ', RunPlanner::engineLabels($record->engines ?? []))),
                        TextEntry::make('started_at')->dateTime(),
                        TextEntry::make('finished_at')->dateTime()->placeholder('Still running'),
                        TextEntry::make('estimated_cost_usd')->label('Estimated cost')->money('usd', 4),
                        TextEntry::make('economy')
                            ->label('Economy mode')
                            ->columnSpanFull()
                            ->visible(fn (Run $record) => $record->batches()->exists())
                            ->state(function (Run $record) {
                                $waiting = $record->batches()->where('status', Batch::SUBMITTED)->get();

                                return $waiting->isEmpty()
                                    ? 'Answered through batch APIs at a lower price.'
                                    : sprintf(
                                        'Waiting for %d answers from %s batch APIs (sent %s). Batches can take up to 24 hours; anything not answered by then is retried in real time.',
                                        $waiting->sum(fn (Batch $batch) => count($batch->result_ids ?? [])),
                                        implode(', ', RunPlanner::engineLabels($waiting->pluck('engine')->unique()->all())),
                                        $waiting->min('submitted_at')?->diffForHumans(),
                                    );
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('brand'))
            ->poll('10s')
            ->columns([
                TextColumn::make('brand.name')->label('Brand')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('trigger')->badge()->toggleable(),
                TextColumn::make('progress')
                    ->state(fn (Run $record) => "{$record->progress()}%")
                    ->description(fn (Run $record) => "{$record->results_done} done · {$record->results_skipped} skipped · {$record->results_failed} failed"),
                TextColumn::make('cost_usd')->label('Cost')->money('usd', 4)->sortable(),
                TextColumn::make('started_at')->label('Started')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('finished_at')->label('Duration')
                    ->state(fn (Run $record) => $record->finished_at && $record->started_at ? $record->started_at->diffForHumans($record->finished_at, true) : null)
                    ->placeholder('Running…'),
            ])
            ->filters([
                SelectFilter::make('brand')->relationship('brand', 'name')->preload(),
                SelectFilter::make('status')->options(RunStatus::class)->multiple(),
                SelectFilter::make('trigger')->options(RunTrigger::class),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [ResultsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRuns::route('/'),
            'view' => Pages\ViewRun::route('/{record}'),
        ];
    }
}
