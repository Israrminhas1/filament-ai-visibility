<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Forms\EngineFields;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
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

    public static function requiresSettingsAccess(): bool
    {
        return true;
    }

    /**
     * URL of Settings with the "Engines & API keys" tab open.
     */
    public static function enginesUrl(): ?string
    {
        return AiVisibilityPlugin::pageUrl(static::class, parameters: ['tab' => 'engines']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runSetup')
                ->label('Run setup again')
                ->icon('heroicon-o-rocket-launch')
                ->color('gray')
                ->url(fn () => AiVisibilityPlugin::pageUrl(Setup::class, parameters: ['restart' => 1]))
                ->visible(fn () => AiVisibilityPlugin::current()?->hasSetupWizard() && Setup::canAccess()),
        ];
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
            'engines_economy' => (bool) ($all['engines']['economy'] ?? false),
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
                    Tab::make('Engines & API keys')
                        ->id('engines')
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
                            Toggle::make('engines_economy')
                                ->label('Economy mode for scheduled runs')
                                ->helperText('OpenAI and Claude answer scheduled runs through their batch APIs: about half the token cost, with answers arriving within 24 hours instead of minutes. Manual runs are always real time. Anything a batch cannot answer is retried in real time.'),
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

                    Tab::make('Analysis & competitors')
                        ->icon('heroicon-o-magnifying-glass-circle')
                        ->schema([
                            Toggle::make('analysis.enabled')
                                ->label('Analyse answers')
                                ->helperText('Sentiment, how strongly each brand is recommended, and the words used to describe it. One AI helper call per ~5 answers; it also finds other company names, so no separate call is needed for that.'),
                            Toggle::make('discovery.enabled')
                                ->label('Discover competitors in answers')
                                ->helperText('After each run, names and sites mentioned alongside the brand are collected and scored.'),
                            Toggle::make('discovery.extract_names')
                                ->label('Find names mentioned without a link')
                                ->helperText('Uses one AI helper call per ~8 answers. Without it, only cited websites are found.'),
                            Toggle::make('discovery.classify')
                                ->label('Classify the top candidates')
                                ->helperText('Reads each candidate\'s website and labels it (direct competitor, review site, marketplace…).'),
                            TextInput::make('discovery.top_n')
                                ->label('Candidates to classify per brand')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(200),
                            TextInput::make('discovery.reclassify_days')
                                ->label('Re-check classifications after (days)')
                                ->numeric()
                                ->minValue(7),
                            Toggle::make('discovery.auto_accept')
                                ->label('Automatically track high-confidence direct competitors')
                                ->helperText('Off by default: you review each one first.'),
                            TagsInput::make('discovery.ignored_domains')
                                ->label('Never suggest these domains')
                                ->placeholder('example.com'),
                        ]),

                    Tab::make('Prompts & keywords')
                        ->icon('heroicon-o-light-bulb')
                        ->schema([
                            Section::make('Prompt generation')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('generation.count')->label('Prompts per request')->numeric()->minValue(1)->maxValue(50),
                                    TextInput::make('generation.persona')->label('Default persona')->placeholder('e.g. owner of a small agency'),
                                    CheckboxList::make('generation.intents')->label('Default intents')->options(PromptIntent::class)->columns(3)->columnSpanFull(),
                                    Toggle::make('generation.ai_review')
                                        ->label('Review generated prompts with AI')
                                        ->helperText('Scores each prompt for realism and relevance; weak ones are rejected. One extra helper call per batch.'),
                                    TextInput::make('generation.min_quality')->label('Minimum quality (1–5)')->numeric()->minValue(1)->maxValue(5),
                                ]),
                            TextInput::make('keywords.sync_days')
                                ->label('Sync keyword sources every (days)')
                                ->numeric()
                                ->minValue(1),
                        ]),

                    Tab::make('AI instructions')
                        ->icon('heroicon-o-document-text')
                        ->schema(collect(Instructions::labels())->map(fn (string $label, string $key) => Textarea::make("instructions.{$key}")
                            ->label($label)
                            ->rows(8)
                            ->placeholder(Instructions::default($key))
                            ->helperText('Leave empty to use the built-in instruction (shown greyed out). Placeholders: ' . collect(Instructions::placeholders()[$key])->map(fn ($p) => '{' . $p . '}')->implode(', ')))
                            ->values()
                            ->all()),

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
        return [
            Toggle::make('alerts.database')
                ->label('Show alerts in the panel')
                ->live(),
            Select::make('alerts.user_ids')
                ->label('Panel users who receive alerts')
                ->multiple()
                ->searchable()
                ->visible(fn ($get) => (bool) $get('alerts.database'))
                ->options(fn () => static::alertRecipientOptions())
                ->getSearchResultsUsing(fn (string $search) => static::alertRecipientOptions($search))
                ->getOptionLabelsUsing(fn (array $values) => static::recipientLabels(static::alertRecipientsQuery()?->whereKey($values)))
                ->helperText('Type a name or email to find more users.'),
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

    /**
     * Users that can be picked as alert recipients. In order: the plugin's
     * alertRecipientsQuery(), the current tenant's users or members, only the
     * current user (tenancy without such a relationship), or every user.
     *
     * @return ?Builder<Model>
     */
    public static function alertRecipientsQuery(): ?Builder
    {
        $userModel = config('auth.providers.users.model');

        if (! $userModel || ! class_exists($userModel)) {
            return null;
        }

        $query = $userModel::query();
        $tenant = static::currentTenant();

        if ($callback = AiVisibilityPlugin::current()?->getAlertRecipientsQuery()) {
            return $callback($query, $tenant) ?? $query;
        }

        if (! $tenant) {
            return $query;
        }

        foreach (['users', 'members'] as $name) {
            if (! method_exists($tenant, $name)) {
                continue;
            }

            $relation = $tenant->{$name}();

            if ($relation instanceof Relation && $relation->getRelated() instanceof $userModel) {
                $key = $relation->getRelated()->getQualifiedKeyName();

                return $query->whereIn($query->getModel()->getQualifiedKeyName(), $relation->getQuery()->select($key));
            }
        }

        // A tenant without a users relationship: never offer other tenants' users.
        return $query->whereKey(auth()->id());
    }

    /**
     * Users to offer as alert recipients. Before searching: the tenant's users when
     * the list is scoped, otherwise only the current user.
     *
     * @return array<int|string, string>
     */
    public static function alertRecipientOptions(?string $search = null): array
    {
        if (filled($search)) {
            return static::recipientLabels(static::searchRecipients($search));
        }

        $query = static::alertRecipientsQuery();

        return static::recipientLabels(static::recipientsAreScoped() ? $query?->limit(20) : $query?->whereKey(auth()->id()));
    }

    /**
     * Whether the recipients are limited (a custom query or a tenant), so listing them is safe.
     */
    protected static function recipientsAreScoped(): bool
    {
        return AiVisibilityPlugin::current()?->getAlertRecipientsQuery() !== null || static::currentTenant() !== null;
    }

    protected static function currentTenant(): ?Model
    {
        try {
            return Filament::hasTenancy() ? Filament::getTenant() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return ?Builder<Model>
     */
    protected static function searchRecipients(string $search): ?Builder
    {
        $query = static::alertRecipientsQuery();

        if (! $query) {
            return null;
        }

        $model = $query->getModel();
        $columns = array_filter(['name', 'email'], fn (string $column) => DatabaseSchema::connection($model->getConnectionName())->hasColumn($model->getTable(), $column));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

        return $query
            ->where(function (Builder $query) use ($columns, $model, $like, $search) {
                foreach ($columns as $column) {
                    $query->orWhere($model->qualifyColumn($column), 'like', $like);
                }

                if (is_numeric($search)) {
                    $query->orWhere($model->getQualifiedKeyName(), $search);
                }
            })
            ->limit(20);
    }

    /**
     * @param  ?Builder<Model>  $query
     * @return array<int|string, string>
     */
    protected static function recipientLabels(?Builder $query): array
    {
        if (! $query) {
            return [];
        }

        return $query->get()
            ->mapWithKeys(fn ($user) => [$user->getKey() => (string) ($user->name ?? $user->email ?? $user->getKey())])
            ->all();
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
                'economy' => (bool) ($state['engines_economy'] ?? false),
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
            'analysis' => ['enabled' => (bool) ($state['analysis']['enabled'] ?? true)],
            'generation' => [
                'count' => (int) ($state['generation']['count'] ?? 10),
                'persona' => $state['generation']['persona'] ?? null,
                'intents' => array_values(array_map(fn ($i) => $i instanceof PromptIntent ? $i->value : $i, (array) ($state['generation']['intents'] ?? []))),
                'ai_review' => (bool) ($state['generation']['ai_review'] ?? true),
                'min_quality' => (int) ($state['generation']['min_quality'] ?? 4),
            ],
            'keywords' => ['sync_days' => (int) ($state['keywords']['sync_days'] ?? 30)],
            'discovery' => [
                'enabled' => (bool) ($state['discovery']['enabled'] ?? true),
                'extract_names' => (bool) ($state['discovery']['extract_names'] ?? true),
                'classify' => (bool) ($state['discovery']['classify'] ?? true),
                'top_n' => (int) ($state['discovery']['top_n'] ?? 25),
                'reclassify_days' => (int) ($state['discovery']['reclassify_days'] ?? 90),
                'auto_accept' => (bool) ($state['discovery']['auto_accept'] ?? false),
                'ignored_domains' => array_values(array_filter(array_map(
                    fn ($domain) => \IsrarMinhas\FilamentAiVisibility\Models\Brand::normalizeDomain((string) $domain),
                    $state['discovery']['ignored_domains'] ?? [],
                ))),
            ],
            // Empty or unchanged instructions fall back to the built-in default.
            'instructions' => collect($state['instructions'] ?? [])
                ->map(fn ($text, $key) => filled($text) && trim($text) !== trim(Instructions::default($key)) ? $text : '')
                ->all(),
        ]);

        $settings->setKillSwitch((bool) ($state['kill_switch'] ?? false));

        // Clear typed keys from the form so they are not kept in the browser.
        $this->mount();

        Notification::make()->title('Settings saved')->success()->send();
    }
}
