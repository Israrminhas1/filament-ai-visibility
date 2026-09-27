<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorSuggester;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HandlesLimitExceptions;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;

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
                $this->suggestAction(),
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

    /**
     * The AI helper proposes competitors; the user picks (and can correct) the
     * ones to add. Nothing is added without a click.
     */
    protected function suggestAction(): Action
    {
        return Action::make('suggestCompetitors')
            ->label('Suggest with AI')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalHeading('Suggested competitors')
            ->modalDescription('Your AI helper names direct competitors from the brand\'s profile. Untick any you don\'t want, correct names or websites, then add them.')
            ->modalSubmitActionLabel('Add selected')
            ->mountUsing(function (?Schema $schema) {
                /** @var Brand $brand */
                $brand = $this->getOwnerRecord();

                try {
                    $suggestions = app(CompetitorSuggester::class)->suggest($brand, $brand->competitors()->pluck('name')->all());
                } catch (HelperUnavailable $e) {
                    Notification::make()->title('Could not suggest competitors')->body($e->getMessage())->warning()->send();
                    $suggestions = [];
                }

                $schema?->fill([
                    'suggestions' => array_map(fn (array $row) => [
                        'add' => true,
                        'name' => $row['name'],
                        'domain' => $row['domain'],
                        'reason' => $row['reason'],
                    ], $suggestions),
                ]);
            })
            ->schema([
                Repeater::make('suggestions')
                    ->hiddenLabel()
                    ->addable(false)
                    ->reorderable(false)
                    ->deletable(false)
                    ->columns(6)
                    ->schema([
                        Toggle::make('add')->label('Add')->inline(false),
                        TextInput::make('name')->required()->columnSpan(2),
                        TextInput::make('domain')->label('Website')->columnSpan(3),
                        Textarea::make('reason')->hiddenLabel()->rows(1)->disabled()->dehydrated(false)->columnSpanFull()
                            ->visible(fn (Get $get) => filled($get('reason'))),
                    ]),
            ])
            ->action(function (array $data) {
                /** @var Brand $brand */
                $brand = $this->getOwnerRecord();
                $picked = collect($data['suggestions'] ?? [])->filter(fn ($row) => ($row['add'] ?? false) && filled($row['name'] ?? null));
                $max = app(Limits::class)->maxCompetitors($brand);
                $room = $max === null ? $picked->count() : max(0, $max - $brand->competitors()->count());

                foreach ($picked->take($room) as $row) {
                    $brand->competitors()->create([
                        'name' => $row['name'],
                        'domains' => array_values(array_filter([Brand::normalizeDomain((string) ($row['domain'] ?? ''))])),
                        'source' => 'suggested',
                    ]);
                }

                $added = min($room, $picked->count());

                Notification::make()
                    ->title('Added ' . $added . ' ' . str('competitor')->plural($added))
                    ->body($picked->count() > $room ? ($picked->count() - $room) . " left out: {$brand->name} has reached its competitor limit." : null)
                    ->status($picked->count() > $room ? 'warning' : 'success')
                    ->send();
            });
    }
}
