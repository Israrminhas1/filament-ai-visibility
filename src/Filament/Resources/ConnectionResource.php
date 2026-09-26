<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSync;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;

class ConnectionResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = Connection::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 35;

    protected static ?string $modelLabel = 'keyword source';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/keyword-sources';
    }

    public static function getNavigationLabel(): string
    {
        return 'Keyword sources';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-arrows-right-left';
    }

    /**
     * Sources hold API keys, so only users who can manage settings add, change,
     * test, sync or remove them. Everyone else sees the list.
     */
    public static function canManageSources(): bool
    {
        return AiVisibilityPlugin::userCanManage();
    }

    public static function canCreate(): bool
    {
        return static::canManageSources() && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageSources() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageSources() && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canManageSources() && parent::canDeleteAny();
    }

    public static function form(Schema $schema): Schema
    {
        $sources = app(KeywordSourceRegistry::class);

        return $schema
            ->columns(1)
            ->components([
                Select::make('brand_id')
                    ->label('Brand')
                    ->options(PromptTable::brandOptions())
                    ->required(),
                Select::make('type')
                    ->label('Source')
                    ->options($sources->options())
                    ->required()
                    ->live()
                    ->disabledOn('edit'),
                Text::make(fn (Get $get) => $get('type') && $sources->has($get('type')) ? $sources->get($get('type'))->description() : '')
                    ->visible(fn (Get $get) => filled($get('type'))),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (Get $get) => $get('type') && $sources->has($get('type')) ? $sources->get($get('type'))->label() : null),
                ...collect($sources->options())
                    ->keys()
                    ->map(fn (string $type) => Group::make($sources->get($type)->formFields())
                        ->visible(fn (Get $get) => $get('type') === $type))
                    ->all(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('brand'))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Connection $record) => app(KeywordSourceRegistry::class)->has($record->type) ? app(KeywordSourceRegistry::class)->get($record->type)->label() : $record->type),
                TextColumn::make('brand.name')->label('Brand')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Connection::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => [Connection::CONNECTED => 'success', Connection::NEEDS_REAUTH => 'danger', Connection::ERROR => 'danger'][$state] ?? 'gray')
                    ->description(fn (Connection $record) => $record->last_error ? str($record->last_error)->limit(80)->toString() : null),
                TextColumn::make('keywords_count')->label('Keywords')->counts('keywords'),
                TextColumn::make('last_synced_at')->label('Last sync')->since()->placeholder('Never'),
                TextColumn::make('next_sync_at')->label('Next sync')->since()->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Sync now')
                    ->icon('heroicon-o-arrow-path')
                    ->authorize(fn () => static::canManageSources())
                    ->action(fn (Connection $record) => static::sync($record)),
                ActionGroup::make([
                    Action::make('test')
                        ->label('Test')
                        ->icon('heroicon-o-bolt')
                        ->authorize(fn () => static::canManageSources())
                        ->action(function (Connection $record) {
                            $result = app(KeywordSourceRegistry::class)->get($record->type)->test($record);

                            Notification::make()->title($result->message)->status($result->ok ? 'success' : 'danger')->send();
                        }),
                    EditAction::make()
                        ->authorize(fn (Connection $record) => static::canEdit($record))
                        ->fillForm(fn (Connection $record) => static::fillData($record))
                        ->using(fn (Connection $record, array $data) => static::saveEdit($record, $data)),
                    DeleteAction::make()
                        ->authorize(fn (Connection $record) => static::canDelete($record)),
                ]),
            ])
            ->emptyStateHeading('No keyword sources yet')
            ->emptyStateDescription('Connect SerpAPI, Google Search Console or DataForSEO to ground prompt generation in real searches. Keywords can also be pasted on the Keywords screen.');
    }

    /**
     * Form state without the stored secrets.
     *
     * @return array<string, mixed>
     */
    public static function fillData(Connection $record): array
    {
        return [
            'brand_id' => $record->brand_id,
            'type' => $record->type,
            'name' => $record->name,
            'config' => $record->config ?? [],
            'credentials' => collect($record->credentials ?? [])->map(fn ($value, $key) => in_array($key, ['login'], true) ? $value : null)->all(),
        ];
    }

    /**
     * Create or update a connection. Empty secret fields keep the saved values.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Connection $record = null): Connection
    {
        $record ??= new Connection(['status' => Connection::CONNECTED, 'next_sync_at' => now()]);

        $credentials = array_filter((array) ($data['credentials'] ?? []), fn ($value) => filled($value));

        $record->fill([
            'brand_id' => $data['brand_id'] ?? $record->brand_id,
            'type' => $data['type'] ?? $record->type,
            'name' => $data['name'],
            'config' => array_filter((array) ($data['config'] ?? []), fn ($value) => $value !== null && $value !== ''),
        ]);

        $stored = $record->credentials ?? [];
        $changed = $record->exists && collect($credentials)->contains(fn ($value, $key) => ($stored[$key] ?? null) !== $value);

        $record->credentials = [...$stored, ...$credentials];

        // New credentials clear an old failure and are tried on the next scheduled sync.
        if ($changed && $record->status !== Connection::DISABLED) {
            $record->forceFill([
                'status' => Connection::CONNECTED,
                'last_error' => null,
                'next_sync_at' => now(),
            ]);
        }

        $record->save();

        return $record;
    }

    /**
     * Saves an edit; when the credentials changed they are tested straight away.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveEdit(Connection $record, array $data): Connection
    {
        $before = $record->credentials ?? [];
        $record = static::save($data, $record);

        if ($record->credentials !== $before && app(KeywordSourceRegistry::class)->has($record->type)) {
            $result = app(KeywordSourceRegistry::class)->get($record->type)->test($record);

            if (! $result->ok) {
                $record->forceFill([
                    'status' => $result->credentialsRejected ? Connection::NEEDS_REAUTH : Connection::ERROR,
                    'last_error' => SourceFailed::redact($result->message),
                ])->save();
            }

            Notification::make()
                ->title($result->ok ? 'Saved and connected' : 'Saved, but the test failed')
                ->body($result->message)
                ->status($result->ok ? 'success' : 'warning')
                ->send();
        }

        return $record;
    }

    public static function sync(Connection $record): void
    {
        try {
            $counts = app(KeywordSync::class)->sync($record);
        } catch (SourceFailed $e) {
            Notification::make()->title('Sync failed')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title("{$counts['created']} new keywords, {$counts['updated']} updated")
            ->body($counts['skipped'] ? "{$counts['skipped']} were not added because of the keyword limit." : null)
            ->status($counts['skipped'] ? 'warning' : 'success')
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageConnections::route('/'),
        ];
    }
}
