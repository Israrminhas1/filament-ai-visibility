<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertEventResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Models\AlertEvent;

class AlertEventResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = AlertEvent::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 80;

    protected static ?string $modelLabel = 'alert';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/alerts';
    }

    public static function getNavigationLabel(): string
    {
        return 'Alerts';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-inbox';
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $unread = AlertEvent::query()->whereNull('read_at')->count();
        } catch (\Throwable) {
            return null;
        }

        return $unread ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                TextColumn::make('title')
                    ->weight(fn (AlertEvent $record) => $record->read_at ? null : 'bold')
                    ->description(fn (AlertEvent $record) => str($record->body)->limit(160)->toString())
                    ->wrap()
                    ->searchable(),
                TextColumn::make('level')->badge()->color(fn (string $state) => ['danger' => 'danger', 'success' => 'success'][$state] ?? 'warning'),
                TextColumn::make('brand.name')->label('Brand')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('unread')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('read_at'),
                        false: fn (Builder $query) => $query->whereNotNull('read_at'),
                    ),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (AlertEvent $record) => filled($record->url))
                    ->action(function (AlertEvent $record, $livewire) {
                        $record->forceFill(['read_at' => $record->read_at ?? now()])->save();

                        return redirect()->to($record->url);
                    }),
                Action::make('markRead')
                    ->label('Mark read')
                    ->icon('heroicon-o-check')
                    ->visible(fn (AlertEvent $record) => $record->read_at === null)
                    ->action(fn (AlertEvent $record) => $record->forceFill(['read_at' => now()])->save()),
            ])
            ->toolbarActions([
                BulkAction::make('markSelectedRead')
                    ->label('Mark read')
                    ->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => AlertEvent::query()->whereKey($records->modelKeys())->update(['read_at' => now()]))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No alerts')
            ->emptyStateDescription('Alerts from your rules, and always-on alerts like paused engines, appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAlertEvents::route('/')];
    }
}
