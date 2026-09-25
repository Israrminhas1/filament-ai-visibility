<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;

/**
 * Per-engine key/model/enable fields, shared by the setup wizard and Settings.
 * State lives under `engines.{key}.enabled|api_key|model`.
 */
class EngineFields
{
    /**
     * @return array<Section>
     */
    public static function make(): array
    {
        return collect(app(EngineRegistry::class)->all())
            ->map(fn (Engine $engine) => static::section($engine))
            ->values()
            ->all();
    }

    protected static function section(Engine $engine): Section
    {
        $key = $engine->key();

        return Section::make($engine->label())
            ->description(fn () => static::keyDescription($key))
            ->compact()
            ->collapsible()
            ->schema([
                Toggle::make("engines.{$key}.enabled")
                    ->label('Track this engine')
                    ->live(),

                TextInput::make("engines.{$key}.api_key")
                    ->label('API key')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->placeholder(fn () => app(KeyResolver::class)->has($key) ? 'Leave empty to keep the current key' : 'Paste your API key')
                    ->helperText('Stored encrypted. Never shown again after saving.'),

                Select::make("engines.{$key}.model")
                    ->label('Model for tracked prompts')
                    ->options(fn () => array_combine($engine->suggestedModels(), $engine->suggestedModels()))
                    ->placeholder($engine->defaultTrackingModel() . ' (default)')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => [$search => $search] + array_filter(
                        array_combine($engine->suggestedModels(), $engine->suggestedModels()),
                        fn ($model) => str_contains($model, $search),
                    ))
                    ->helperText('Pick the model closest to what users of this assistant see. You can type any model name.'),

                Actions::make([
                    Action::make("test_{$key}")
                        ->label('Test key')
                        ->icon('heroicon-o-bolt')
                        ->color('gray')
                        ->action(function (Get $get) use ($key, $engine) {
                            $typed = $get("engines.{$key}.api_key");
                            $result = app(EngineManager::class)->test($key, filled($typed) ? $typed : null);

                            Notification::make()
                                ->title($engine->label() . ': ' . $result->message)
                                ->body($result->ok ? null : $result->reason?->fix())
                                ->status($result->ok ? 'success' : 'danger')
                                ->send();
                        }),
                ]),
            ])
            ->columns(1);
    }

    protected static function keyDescription(string $engine): string
    {
        $source = app(KeyResolver::class)->source($engine)['source'];

        return match ($source) {
            'panel' => 'Key saved.',
            'ai-monitor' => 'Using the key from AI Monitor. Paste a key here to override it.',
            'env' => 'Using the key from your .env file. Paste a key here to override it.',
            default => 'No key yet.',
        };
    }

    /**
     * Current state for filling the form. Keys are never sent back to the browser.
     *
     * @return array<string, array{enabled: bool, api_key: null, model: ?string}>
     */
    public static function fill(array $enabled, array $models): array
    {
        return collect(app(EngineRegistry::class)->all())
            ->mapWithKeys(fn (Engine $engine, string $key) => [$key => [
                'enabled' => in_array($key, $enabled, true),
                'api_key' => null,
                'model' => $models[$key] ?? null,
            ]])
            ->all();
    }

    /**
     * Save typed keys and return the enabled engines and chosen models.
     *
     * @param  array<string, array{enabled?: bool, api_key?: ?string, model?: ?string}>  $state
     * @return array{enabled: array<string>, models: array<string, string>}
     */
    public static function save(array $state): array
    {
        $keys = app(KeyResolver::class);
        $enabled = [];
        $models = [];

        foreach ($state as $engine => $values) {
            if (filled($values['api_key'] ?? null)) {
                $keys->store($engine, $values['api_key']);
            }

            if ($values['enabled'] ?? false) {
                $enabled[] = $engine;
            }

            if (filled($values['model'] ?? null)) {
                $models[$engine] = $values['model'];
            }
        }

        return ['enabled' => $enabled, 'models' => $models];
    }
}
