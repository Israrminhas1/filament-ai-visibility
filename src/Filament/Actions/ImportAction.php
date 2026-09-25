<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\Storage;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;

/**
 * "Add prompts" / "Add keywords": paste one per line or upload a CSV.
 */
class ImportAction
{
    /**
     * @param  (callable(): ?Brand)|null  $brand  Fixed brand (relation managers); otherwise a brand select is shown.
     */
    public static function prompts(?callable $brand = null): Action
    {
        return Action::make('addPrompts')
            ->label('Add prompts')
            ->icon('heroicon-o-plus')
            ->modalDescription('Paste one prompt per line, or upload a CSV with a "text" column (optional columns: topic, intent, tags). Duplicates are skipped.')
            ->schema([
                ...static::brandField($brand),
                static::inputTabs('text', "What is the best CRM for small agencies?\nWhich CRM integrates with Slack?"),
                Toggle::make('activate')
                    ->label('Activate now')
                    ->default(true)
                    ->helperText('Prompts over the active-prompt limit are saved as paused.'),
            ])
            ->action(function (array $data) use ($brand) {
                $target = $brand ? $brand() : Brand::query()->findOrFail($data['brand_id']);
                $rows = static::rows($data, 'text');

                $result = app(Importer::class)->prompts(
                    $target,
                    $rows,
                    filled($data['csv'] ?? null) ? PromptSource::Imported : PromptSource::Manual,
                    (bool) $data['activate'],
                );

                Notification::make()
                    ->title("Added {$result['created']} prompts")
                    ->body(collect([
                        $result['skipped'] ? "{$result['skipped']} duplicates skipped." : null,
                        $result['paused'] ? "{$result['paused']} saved as paused (active-prompt limit)." : null,
                    ])->filter()->implode(' ') ?: null)
                    ->status($result['paused'] ? 'warning' : 'success')
                    ->send();
            });
    }

    public static function keywords(?callable $brand = null): Action
    {
        return Action::make('addKeywords')
            ->label('Add keywords')
            ->icon('heroicon-o-plus')
            ->modalDescription('Paste one keyword per line, or upload a CSV with a "keyword" column (optional columns: search_volume, clicks, impressions).')
            ->schema([
                ...static::brandField($brand),
                static::inputTabs('keyword', "crm for agencies\nbest crm small business"),
            ])
            ->action(function (array $data) use ($brand) {
                $target = $brand ? $brand() : Brand::query()->findOrFail($data['brand_id']);
                $rows = static::rows($data, 'keyword');

                $result = app(Importer::class)->keywords(
                    $target,
                    $rows,
                    filled($data['csv'] ?? null) ? KeywordSource::Csv : KeywordSource::Manual,
                );

                Notification::make()
                    ->title("Added {$result['created']} keywords")
                    ->body($result['skipped'] ? "{$result['skipped']} duplicates skipped." : null)
                    ->success()
                    ->send();
            });
    }

    protected static function brandField(?callable $brand): array
    {
        return $brand ? [] : [
            Select::make('brand_id')
                ->label('Brand')
                ->options(PromptTable::brandOptions())
                ->required(),
        ];
    }

    protected static function inputTabs(string $column, string $placeholder): Tabs
    {
        return Tabs::make('input')->tabs([
            Tab::make('Paste')->schema([
                Textarea::make('lines')->hiddenLabel()->rows(8)->placeholder($placeholder),
            ]),
            Tab::make('Upload CSV')->schema([
                FileUpload::make('csv')
                    ->hiddenLabel()
                    ->disk('local')
                    ->directory('ai-visibility-imports')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->maxSize(5120),
            ]),
        ]);
    }

    /**
     * @return array<int, string|array<string, string>>
     */
    protected static function rows(array $data, string $column): array
    {
        $rows = Importer::lines($data['lines'] ?? null);

        if (filled($data['csv'] ?? null)) {
            $path = Storage::disk('local')->path($data['csv']);
            $rows = [...$rows, ...Importer::readCsv($path, $column)];
            Storage::disk('local')->delete($data['csv']);
        }

        return $rows;
    }
}
