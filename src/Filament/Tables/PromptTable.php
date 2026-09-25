<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Tables;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;

class PromptTable
{
    /**
     * @return array<TextColumn>
     */
    public static function columns(bool $withBrand = false): array
    {
        return array_values(array_filter([
            TextColumn::make('text')
                ->label('Prompt')
                ->searchable()
                ->wrap()
                ->lineClamp(2),
            $withBrand ? TextColumn::make('brand.name')->label('Brand')->sortable()->searchable() : null,
            TextColumn::make('topic.name')->label('Topic')->placeholder('—')->sortable()->toggleable(),
            TextColumn::make('intent')->badge()->sortable()->toggleable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('source')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('keywords_count')->label('Keywords')->counts('keywords')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_run_at')->label('Last run')->since()->placeholder('Never')->sortable()->toggleable(),
        ]));
    }

    /**
     * @return array<SelectFilter>
     */
    public static function filters(bool $withBrand = false): array
    {
        return array_values(array_filter([
            $withBrand ? SelectFilter::make('brand')->relationship('brand', 'name')->preload() : null,
            SelectFilter::make('status')->options(PromptStatus::class)->multiple(),
            SelectFilter::make('intent')->options(PromptIntent::class)->multiple(),
            SelectFilter::make('source')->options(PromptSource::class)->multiple(),
            SelectFilter::make('topic')->relationship('topic', 'name')->preload(),
        ]));
    }

    public static function bulkActions(): BulkActionGroup
    {
        return BulkActionGroup::make([
            BulkAction::make('activate')
                ->label('Activate')
                ->icon('heroicon-o-play')
                ->action(function (Collection $records) {
                    $activated = 0;
                    $skipped = 0;

                    foreach ($records->groupBy('brand_id') as $prompts) {
                        $brand = $prompts->first()->brand;
                        $remaining = app(Limits::class)->remainingActivePrompts($brand);

                        foreach ($prompts->where('status', '!=', PromptStatus::Active) as $prompt) {
                            if ($remaining !== null && $remaining <= 0) {
                                $skipped++;

                                continue;
                            }

                            $prompt->update(['status' => PromptStatus::Active]);
                            $activated++;
                            $remaining = $remaining === null ? null : $remaining - 1;
                        }
                    }

                    Notification::make()
                        ->title("Activated {$activated} prompts")
                        ->body($skipped ? "{$skipped} were not activated because of the active-prompt limit." : null)
                        ->status($skipped ? 'warning' : 'success')
                        ->send();
                })
                ->deselectRecordsAfterCompletion(),

            BulkAction::make('pause')
                ->label('Pause')
                ->icon('heroicon-o-pause')
                ->action(fn (Collection $records) => $records->each->update(['status' => PromptStatus::Paused]))
                ->deselectRecordsAfterCompletion(),

            BulkAction::make('moveToTopic')
                ->label('Move to topic')
                ->icon('heroicon-o-folder')
                ->schema(fn (Collection $records) => [
                    Select::make('topic_id')
                        ->label('Topic')
                        ->placeholder('No topic')
                        ->options(Topic::query()->whereIn('brand_id', $records->pluck('brand_id')->unique())->pluck('name', 'id')),
                ])
                ->action(function (Collection $records, array $data) {
                    $topic = filled($data['topic_id']) ? Topic::query()->find($data['topic_id']) : null;

                    $records
                        ->filter(fn (Prompt $prompt) => ! $topic || $prompt->brand_id === $topic->brand_id)
                        ->each->update(['topic_id' => $topic?->getKey()]);
                })
                ->deselectRecordsAfterCompletion(),

            DeleteBulkAction::make(),
        ]);
    }

    /**
     * Brands the current user can pick, for "add prompts" style actions.
     *
     * @return array<int, string>
     */
    public static function brandOptions(): array
    {
        return Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
