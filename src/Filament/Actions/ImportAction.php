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
use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;
use Throwable;

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
            ->modalDescription('Paste one prompt per line, or upload a CSV with a "text" or "prompt" column (optional columns: topic, intent, tags). Duplicates are skipped.')
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
                $result = static::attempt(fn () => app(Importer::class)->prompts(
                    $target,
                    static::rows($data, 'text'),
                    filled($data['csv'] ?? null) ? PromptSource::Imported : PromptSource::Manual,
                    (bool) $data['activate'],
                ));

                if ($result === null) {
                    return;
                }

                $invalid = $result['invalid'] ?? 0;
                $truncated = $result['truncated'] ?? 0;

                Notification::make()
                    ->title("Added {$result['created']} prompts")
                    ->body(collect([
                        $result['skipped'] ? "{$result['skipped']} duplicates skipped." : null,
                        $result['paused'] ? "{$result['paused']} saved as paused (active-prompt limit)." : null,
                        $invalid ? "{$invalid} rows could not be read (unsupported text encoding)." : null,
                        $truncated ? "{$truncated} topic names were shortened to 255 characters." : null,
                    ])->filter()->implode(' ') ?: null)
                    ->status($result['paused'] || $invalid ? 'warning' : 'success')
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
                $result = static::attempt(fn () => app(Importer::class)->keywords(
                    $target,
                    static::rows($data, 'keyword'),
                    filled($data['csv'] ?? null) ? KeywordSource::Csv : KeywordSource::Manual,
                ));

                if ($result === null) {
                    return;
                }

                $tooLong = $result['too_long'] ?? 0;
                $invalid = $result['invalid'] ?? 0;

                Notification::make()
                    ->title("Added {$result['created']} keywords")
                    ->body(collect([
                        $result['skipped'] ? "{$result['skipped']} duplicates skipped." : null,
                        $tooLong ? "{$tooLong} keywords longer than 255 characters skipped." : null,
                        $invalid ? "{$invalid} rows could not be read (unsupported text encoding)." : null,
                    ])->filter()->implode(' ') ?: null)
                    ->status($tooLong || $invalid ? 'warning' : 'success')
                    ->send();
            });
    }

    /**
     * Runs an import. Anything unexpected is reported and shown as a friendly
     * message instead of an error page; limit errors are left to the page.
     *
     * @param  callable(): array<string, int>  $import
     * @return array<string, int>|null
     */
    protected static function attempt(callable $import): ?array
    {
        try {
            return $import();
        } catch (LimitExceeded $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Import failed')
                ->body('Nothing was imported. Check the file is a CSV saved as UTF-8 or Windows-1252, then try again.')
                ->danger()
                ->persistent()
                ->send();

            return null;
        }
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
            try {
                $rows = [...$rows, ...Importer::readCsv(Storage::disk('local')->path($data['csv']), $column)];
            } finally {
                Storage::disk('local')->delete($data['csv']);
            }
        }

        return $rows;
    }
}
