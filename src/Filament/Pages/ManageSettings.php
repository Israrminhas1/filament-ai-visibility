<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Forms\EngineFields;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

class ManageSettings extends Page
{
    use HasAiVisibilityNavigation;

    protected static int $aiVisibilitySort = 90;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/settings';
    }

    public static function getNavigationLabel(): string
    {
        return 'Settings';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public function getTitle(): string
    {
        return 'AI Visibility settings';
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $all = $settings->all();

        $this->form->fill([
            ...$all,
            'limits' => array_map(fn ($value) => $value ? $value : null, $all['limits'] ?? []),
            'engines' => EngineFields::fill($all['engines']['enabled'] ?? [], $all['engines']['models'] ?? []),
            'engines_rpm' => $all['engines']['requests_per_minute'] ?? 20,
            'kill_switch' => $settings->killSwitch(),
        ]);
    }

    public static function schemaComponents(bool $includeEngines = true): array
    {
        $engineOptions = ['auto' => 'Automatic (first engine with a working key)'] + app(EngineRegistry::class)->options();

        return [
            Tabs::make('settings')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Engines')
                        ->icon('heroicon-o-cpu-chip')
                        ->visible($includeEngines)
                        ->schema([
                            ...($includeEngines ? EngineFields::make() : []),
                            TextInput::make('engines_rpm')
                                ->label('Requests per minute, per engine')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(600)
                                ->helperText('Keeps runs under provider rate limits. Lower it if an engine keeps getting rate limited.'),
                        ]),

                    Tab::make('Runs & limits')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->schema([
                            Section::make('Runs')
                                ->columns(3)
                                ->schema([
                                    Select::make('runs.frequency')
                                        ->label('Default run frequency')
                                        ->options(RunFrequency::class)
                                        ->required(),
                                    TimePicker::make('runs.time')
                                        ->label('Run at')
                                        ->seconds(false)
                                        ->helperText('Server time.'),
                                    TextInput::make('runs.samples')
                                        ->label('Samples per prompt')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(5)
                                        ->helperText('AI answers vary. Asking each prompt 2–3 times gives steadier numbers but multiplies cost.'),
                                ]),
                            Section::make('Limits')
                                ->description('Leave empty for no limit. Brands can have their own limits.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('limits.max_brands')->label('Max brands')->numeric()->minValue(1),
                                    TextInput::make('limits.max_competitors_per_brand')->label('Max competitors per brand')->numeric()->minValue(1),
                                    TextInput::make('limits.max_active_prompts_per_brand')->label('Max active prompts per brand')->numeric()->minValue(1),
                                    TextInput::make('limits.max_keywords_per_brand')->label('Max keywords per brand')->numeric()->minValue(1),
                                    TextInput::make('limits.max_runs_per_brand_per_day')->label('Max runs per brand per day')->numeric()->minValue(1),
                                ]),
                        ]),

                    Tab::make('Budget')
                        ->icon('heroicon-o-banknotes')
                        ->schema([
                            TextInput::make('budget.monthly_usd')
                                ->label('Monthly budget (USD)')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->helperText('Covers every AI call AI Visibility makes. Leave empty for no budget.'),
                            Toggle::make('budget.stop_at_budget')
                                ->label('Pause all engines when the budget is reached')
                                ->helperText('Recommended. When off, you only get an alert.'),
                        ]),

                    Tab::make('AI helpers')
                        ->icon('heroicon-o-sparkles')
                        ->schema([
                            Select::make('helpers.engine')
                                ->label('Engine for analysis, classification and prompt generation')
                                ->options($engineOptions)
                                ->required()
                                ->helperText('Automatic works with any single key and switches engines if one is paused.'),
                            TextInput::make('helpers.model')
                                ->label('Model')
                                ->placeholder('Engine default (a cheaper model)')
                                ->helperText('Only used when a specific engine is chosen above.'),
                        ]),

                    Tab::make('Alerts')
                        ->icon('heroicon-o-bell-alert')
                        ->schema(static::alertComponents()),

                    Tab::make('Safety & data')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Toggle::make('kill_switch')
                                ->label('Pause everything')
                                ->helperText('Stops all scheduled and queued AI Visibility work until switched off.'),
                            TextInput::make('data.keep_answers_days')
                                ->label('Keep full answer text for (days)')
                                ->numeric()
                                ->minValue(7)
                                ->helperText('Older answers are removed to save space. Metrics are kept.'),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    public static function alertComponents(): array
    {
        $userModel = config('auth.providers.users.model');

        return [
            Toggle::make('alerts.database')
                ->label('Show alerts in the panel')
                ->live(),
            Select::make('alerts.user_ids')
                ->label('Panel users who receive alerts')
                ->multiple()
                ->visible(fn ($get) => (bool) $get('alerts.database'))
                ->options(fn () => $userModel && class_exists($userModel)
                    ? $userModel::query()->limit(200)->get()->mapWithKeys(fn ($user) => [$user->getKey() => $user->name ?? $user->email ?? $user->getKey()])->all()
                    : []),
            TagsInput::make('alerts.emails')
                ->label('Email alerts to')
                ->placeholder('name@example.com')
                ->nestedRecursiveRules(['email']),
            TextInput::make('alerts.slack_webhook')
                ->label('Slack incoming webhook URL')
                ->url()
                ->placeholder('https://hooks.slack.com/services/...'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(static::schemaComponents())
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save'),
                    ])->alignment(Alignment::Start),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(Settings::class);

        $engines = EngineFields::save($state['engines'] ?? []);

        $settings->set([
            'engines' => [
                'enabled' => $engines['enabled'],
                'models' => $engines['models'],
                'requests_per_minute' => (int) ($state['engines_rpm'] ?? 20),
            ],
            'runs' => $state['runs'] ?? [],
            // 0 means "no limit"; null would fall back to the default.
            'limits' => array_map(fn ($value) => filled($value) ? (int) $value : 0, $state['limits'] ?? []),
            'budget' => [
                'monthly_usd' => filled($state['budget']['monthly_usd'] ?? null) ? (float) $state['budget']['monthly_usd'] : null,
                'stop_at_budget' => (bool) ($state['budget']['stop_at_budget'] ?? true),
            ],
            'helpers' => $state['helpers'] ?? [],
            'alerts' => [
                'database' => (bool) ($state['alerts']['database'] ?? true),
                'user_ids' => array_values($state['alerts']['user_ids'] ?? []),
                'emails' => array_values($state['alerts']['emails'] ?? []),
                'slack_webhook' => $state['alerts']['slack_webhook'] ?? null,
            ],
            'data' => $state['data'] ?? [],
        ]);

        $settings->setKillSwitch((bool) ($state['kill_switch'] ?? false));

        // Clear typed keys from the form so they are not kept in the browser.
        $this->mount();

        Notification::make()->title('Settings saved')->success()->send();
    }
}
