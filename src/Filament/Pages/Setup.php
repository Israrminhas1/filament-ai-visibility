<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorSuggester;
use IsrarMinhas\FilamentAiVisibility\Prompts\PromptGenerator;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Forms\EngineFields;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\CostEstimator;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Text as TextHelper;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\WebsiteProfile;

class Setup extends Page
{
    use HasAiVisibilityNavigation;

    protected static int $aiVisibilitySort = 0;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/setup';
    }

    public static function getNavigationLabel(): string
    {
        return 'Setup';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-rocket-launch';
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Shown in the navigation only until setup is complete; later it is reached from Settings.
        try {
            return ! app(Settings::class)->isSetupComplete();
        } catch (\Throwable) {
            return true;
        }
    }

    public function getTitle(): string
    {
        return 'Set up AI Visibility';
    }

    public function getSubheading(): ?string
    {
        return 'A few steps to connect your AI engines, add your brand and choose what to track. Progress is saved after each step.';
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $all = $settings->all();
        $brand = Brand::query()->oldest()->first();

        app(SystemHealth::class)->pingQueue();

        $this->form->fill([
            'engines' => EngineFields::fill($all['engines']['enabled'] ?? [], $all['engines']['models'] ?? []),
            'runs' => $all['runs'],
            'budget' => $all['budget'],
            'limits' => array_map(fn ($value) => $value ?: null, $all['limits']),
            'alerts' => [
                ...$all['alerts'],
                'user_ids' => $all['alerts']['user_ids'] ?: array_filter([auth()->id()]),
            ],
            'brand' => $brand ? [
                'id' => $brand->getKey(),
                'name' => $brand->name,
                'domain' => $brand->primaryDomain(),
                'aliases' => $brand->aliases,
                'description' => $brand->description,
                'industry' => $brand->industry,
                'market' => $brand->market,
            ] : [],
            'competitors' => $brand ? $brand->competitors->map(fn ($competitor) => [
                'id' => $competitor->getKey(),
                'name' => $competitor->name,
                'domain' => $competitor->domains[0] ?? null,
            ])->all() : [],
            'keywords_text' => null,
            'prompts' => $this->promptRows($brand),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->systemStep(),
                    $this->enginesStep(),
                    $this->budgetStep(),
                    $this->brandStep(),
                    $this->competitorsStep(),
                    $this->keywordsStep(),
                    $this->promptsStep(),
                    $this->alertsStep(),
                    $this->reviewStep(),
                ])
                    ->startOnStep(fn () => min(app(Settings::class)->setupStep(), 9))
                    ->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit" size="sm" icon="heroicon-o-check">Finish setup</x-filament::button>'))),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('finish'),
        ]);
    }

    protected function systemStep(): Step
    {
        return Step::make('System')
            ->icon('heroicon-o-server-stack')
            ->description('Queue and scheduler')
            ->schema([
                View::make('ai-visibility::setup.system-checks')
                    ->viewData(fn () => ['checks' => app(SystemHealth::class)->checks()]),
                Actions::make([
                    Action::make('recheck')
                        ->label('Re-check')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(fn () => app(SystemHealth::class)->pingQueue()),
                ]),
                Checkbox::make('continue_with_warnings')
                    ->label('Continue anyway. I will fix these before tracking starts.')
                    ->visible(fn () => app(SystemHealth::class)->hasBlockingFailures()),
            ])
            ->afterValidation(function (Get $get) {
                if (app(SystemHealth::class)->hasBlockingFailures() && ! $get('continue_with_warnings')) {
                    $this->fail('Some system checks failed', 'Fix them and click "Re-check", or tick "Continue anyway".');
                }

                app(Settings::class)->setSetupStep(2);
            });
    }

    protected function enginesStep(): Step
    {
        return Step::make('Engines')
            ->icon('heroicon-o-cpu-chip')
            ->description('API keys')
            ->schema([
                Text::make('Turn on the AI engines you want to track and add their API keys. One engine is enough to start: every feature works with a single key.'),
                ...EngineFields::make(),
            ])
            ->afterValidation(function (Get $get, Set $set) {
                $engines = EngineFields::save($get('engines') ?? []);

                if ($engines['enabled'] === []) {
                    $this->fail('Turn on at least one engine', 'AI Visibility needs one engine with a working key.');
                }

                $manager = app(EngineManager::class);
                $failed = [];

                foreach ($engines['enabled'] as $engine) {
                    $result = $manager->test($engine);

                    if (! $result->ok) {
                        $failed[] = app(EngineRegistry::class)->get($engine)->label() . ': ' . $result->message;
                    }
                }

                if ($failed !== []) {
                    $this->fail('Some keys did not work', implode("\n", $failed) . "\n\nFix the key or turn that engine off.");
                }

                foreach ($engines['enabled'] as $engine) {
                    $manager->resume($engine);
                }

                app(Settings::class)->set(['engines' => ['enabled' => $engines['enabled'], 'models' => $engines['models']]]);

                // Never keep typed keys in the browser.
                foreach (array_keys($get('engines') ?? []) as $engine) {
                    $set("engines.{$engine}.api_key", null);
                }

                app(Settings::class)->setSetupStep(3);
            });
    }

    protected function budgetStep(): Step
    {
        return Step::make('Budget')
            ->icon('heroicon-o-banknotes')
            ->description('Spend and limits')
            ->columns(2)
            ->schema([
                Select::make('runs.frequency')
                    ->label('How often to run')
                    ->options(RunFrequency::class)
                    ->required()
                    ->live(),
                TextInput::make('runs.samples')
                    ->label('Samples per prompt')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(5)
                    ->required()
                    ->live(debounce: 500)
                    ->helperText('1 is fine to start. 2–3 gives steadier numbers.'),
                TextInput::make('limits.max_active_prompts_per_brand')
                    ->label('Max active prompts per brand')
                    ->numeric()
                    ->minValue(1)
                    ->live(debounce: 500),
                TextInput::make('budget.monthly_usd')
                    ->label('Monthly budget (USD)')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('$')
                    ->helperText('Engines pause automatically when it is reached. Leave empty for no budget.'),
                Section::make('Estimated cost')
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        Text::make(fn (Get $get) => $this->estimateText($get)),
                    ]),
            ])
            ->afterValidation(function (Get $get) {
                $maxPrompts = $get('limits.max_active_prompts_per_brand');

                app(Settings::class)->set([
                    'runs' => ['frequency' => $get('runs.frequency'), 'samples' => (int) $get('runs.samples')],
                    'limits' => ['max_active_prompts_per_brand' => filled($maxPrompts) ? (int) $maxPrompts : 0],
                    'budget' => ['monthly_usd' => filled($get('budget.monthly_usd')) ? (float) $get('budget.monthly_usd') : null],
                ]);

                app(Settings::class)->setSetupStep(4);
            });
    }

    protected function estimateText(Get $get): string
    {
        $engines = app(EngineManager::class)->enabled();
        $prompts = (int) ($get('limits.max_active_prompts_per_brand') ?: 25);
        $samples = max(1, (int) $get('runs.samples'));
        $frequency = $get('runs.frequency');

        if ($engines === []) {
            return 'Enable an engine to see an estimate.';
        }

        $perRun = CostEstimator::costPerRun($prompts, $engines, $samples);
        $monthly = CostEstimator::monthly($prompts, $engines, $samples, $frequency);

        return sprintf(
            'About %s per run and %s per month for %d prompts × %d engine(s) × %d sample(s). This is a rough estimate; real costs are measured once runs start.',
            CostEstimator::format($perRun),
            $frequency === RunFrequency::Manual->value || $frequency === RunFrequency::Manual ? '(manual runs only)' : CostEstimator::format($monthly),
            $prompts,
            count($engines),
            $samples,
        );
    }

    protected function brandStep(): Step
    {
        return Step::make('Brand')
            ->icon('heroicon-o-building-storefront')
            ->description('What to track')
            ->columns(2)
            ->schema([
                TextInput::make('brand.domain')
                    ->label('Website')
                    ->placeholder('acme.com')
                    ->required()
                    ->suffixAction(
                        Action::make('fetchWebsite')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->tooltip('Fill in from the website')
                            ->action(fn (Get $get, Set $set) => $this->prefillFromWebsite($get, $set)),
                    ),
                TextInput::make('brand.name')
                    ->label('Brand name')
                    ->required()
                    ->maxLength(255),
                TagsInput::make('brand.aliases')
                    ->label('Other names')
                    ->placeholder('Acme Inc, AcmeHQ')
                    ->helperText('Spellings and product names that should count as a mention.')
                    ->columnSpanFull(),
                Textarea::make('brand.description')
                    ->label('What the brand offers')
                    ->rows(3)
                    ->helperText('Used to generate prompts and recognise competitors.')
                    ->columnSpanFull(),
                TextInput::make('brand.industry')->label('Industry')->placeholder('CRM software'),
                TextInput::make('brand.market')->label('Market')->placeholder('United Kingdom'),
            ])
            ->afterValidation(function (Get $get, Set $set) {
                $data = $get('brand');
                $brand = filled($data['id'] ?? null) ? Brand::query()->find($data['id']) : null;

                if (! $brand) {
                    try {
                        app(Limits::class)->ensureCanCreateBrand();
                    } catch (LimitExceeded $e) {
                        $this->fail('Brand limit reached', $e->getMessage());
                    }

                    $brand = new Brand(['run_frequency' => app(Settings::class)->get('runs.frequency', 'weekly')]);
                }

                $brand->fill([
                    'name' => $data['name'],
                    'domains' => array_values(array_unique(array_filter([$data['domain'], ...($brand->domains ?? [])]))),
                    'aliases' => $data['aliases'] ?? [],
                    'description' => $data['description'] ?? null,
                    'industry' => $data['industry'] ?? null,
                    'market' => $data['market'] ?? null,
                ])->save();

                $set('brand.id', $brand->getKey());
                app(Settings::class)->setSetupStep(5);
            });
    }

    protected function prefillFromWebsite(Get $get, Set $set): void
    {
        $domain = Brand::normalizeDomain((string) $get('brand.domain'));

        if (! $domain) {
            Notification::make()->title('Enter a website first')->warning()->send();

            return;
        }

        $profile = app(WebsiteProfile::class)->fetch($domain);

        if (! $profile) {
            Notification::make()->title('Could not read ' . $domain)->body('Fill in the details manually.')->warning()->send();

            return;
        }

        $set('brand.domain', $domain);

        if (blank($get('brand.name')) && $profile['name']) {
            $set('brand.name', $profile['name']);
        }

        if (blank($get('brand.description')) && $profile['description']) {
            $set('brand.description', $profile['description']);
        }

        Notification::make()->title('Details filled in from ' . $domain)->body('Check them before continuing.')->success()->send();
    }

    /**
     * Add AI-suggested competitors to the list, for the user to review before continuing.
     */
    protected function suggestCompetitors(Get $get, Set $set): void
    {
        $brand = $this->brand();
        $rows = collect($get('competitors') ?? []);

        try {
            $suggestions = app(CompetitorSuggester::class)->suggest($brand, $rows->pluck('name')->filter()->all());
        } catch (HelperUnavailable $e) {
            Notification::make()->title('Could not suggest competitors')->body($e->getMessage())->warning()->send();

            return;
        }

        $max = app(Limits::class)->maxCompetitors($brand);
        $room = $max === null ? count($suggestions) : max(0, $max - $rows->count());

        foreach (array_slice($suggestions, 0, $room) as $suggestion) {
            $rows->put((string) str()->uuid(), ['name' => $suggestion['name'], 'domain' => $suggestion['domain']]);
        }

        $set('competitors', $rows->all());

        Notification::make()
            ->title('Added ' . min($room, count($suggestions)) . ' suggestions')
            ->body('Remove any that are not real competitors, then continue.')
            ->success()
            ->send();
    }

    protected function competitorsStep(): Step
    {
        return Step::make('Competitors')
            ->icon('heroicon-o-users')
            ->description('Who to compare with')
            ->schema([
                Text::make('Add the competitors you already know. AI Visibility also discovers competitors from AI answers later, so a few is enough.'),
                Actions::make([
                    Action::make('suggestCompetitors')
                        ->label('Suggest with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->action(fn (Get $get, Set $set) => $this->suggestCompetitors($get, $set)),
                ])->key('competitorActions'),
                Repeater::make('competitors')
                    ->hiddenLabel()
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add competitor')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('domain')->label('Website')->placeholder('competitor.com'),
                    ]),
            ])
            ->afterValidation(function (Get $get, Set $set) {
                $brand = $this->brand();
                $rows = collect($get('competitors') ?? []);
                $keep = [];
                $max = app(Limits::class)->maxCompetitors($brand);

                if ($max !== null && $rows->count() > $max) {
                    $this->fail('Too many competitors', "The limit is {$max} competitors per brand.");
                }

                foreach ($rows as $index => $row) {
                    $competitor = filled($row['id'] ?? null)
                        ? $brand->competitors()->find($row['id'])
                        : null;

                    $competitor ??= $brand->competitors()->make();
                    $competitor->fill([
                        'name' => $row['name'],
                        'domains' => array_filter([$row['domain'] ?? null]),
                    ])->save();

                    $keep[] = $competitor->getKey();
                }

                $brand->competitors()->where('source', 'manual')->whereKeyNot($keep)->delete();
                $set('competitors', $brand->competitors()->get()->map(fn ($c) => ['id' => $c->getKey(), 'name' => $c->name, 'domain' => $c->domains[0] ?? null])->all());

                app(Settings::class)->setSetupStep(6);
            });
    }

    protected function keywordsStep(): Step
    {
        return Step::make('Keywords')
            ->icon('heroicon-o-key')
            ->description('Optional')
            ->schema([
                Text::make('Optional. Paste the search keywords that matter to you, one per line. They are used to generate realistic prompts. You can also connect keyword sources later.'),
                Textarea::make('keywords_text')
                    ->hiddenLabel()
                    ->rows(8)
                    ->placeholder("crm for agencies\nbest crm small business\nhubspot alternatives"),
            ])
            ->afterValidation(function (Get $get, Set $set) {
                $lines = Importer::lines($get('keywords_text'));

                if ($lines !== []) {
                    try {
                        $result = app(Importer::class)->keywords($this->brand(), $lines, KeywordSource::Manual);
                    } catch (LimitExceeded $e) {
                        $this->fail('Keyword limit reached', $e->getMessage());
                    }

                    Notification::make()->title("Added {$result['created']} keywords")->success()->send();
                    $set('keywords_text', null);
                }

                app(Settings::class)->setSetupStep(7);
            });
    }

    /**
     * Fill the prompt box with AI-written questions (based on the keywords, if any) for review.
     */
    protected function generatePrompts(Get $get, Set $set): void
    {
        $brand = $this->brand();

        try {
            $result = app(PromptGenerator::class)->generate($brand, save: false);
        } catch (HelperUnavailable $e) {
            Notification::make()->title('Could not generate prompts')->body($e->getMessage())->warning()->send();

            return;
        }

        $good = $result['candidates']->where('passed', true)->pluck('text');
        $rows = collect($get('prompts') ?? [])->filter(fn ($row) => filled($row['text'] ?? null));
        $have = $rows->map(fn ($row) => TextHelper::hash($row['text']))->flip();

        foreach ($good as $text) {
            if (! $have->has(TextHelper::hash($text))) {
                $rows->put((string) Str::uuid(), ['id' => null, 'text' => $text]);
            }
        }

        $set('prompts', $rows->all());

        Notification::make()
            ->title("Added {$good->count()} questions")
            ->body('Edit or delete any you don\'t want, then continue.' . ($result['candidates']->where('passed', false)->count() ? ' Weak ideas were left out.' : ''))
            ->success()
            ->send();
    }

    protected function promptsStep(): Step
    {
        return Step::make('Prompts')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->description('Questions to track')
            ->schema([
                Text::make('Add the questions your customers ask AI assistants, one per row. Don\'t include your brand name.'),
                Actions::make([
                    Action::make('generatePrompts')
                        ->label('Generate with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->action(fn (Get $get, Set $set) => $this->generatePrompts($get, $set)),
                ])->key('promptActions'),
                Repeater::make('prompts')
                    ->hiddenLabel()
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->addActionLabel('Add question')
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('text')
                            ->hiddenLabel()
                            ->maxLength(2000)
                            ->placeholder('e.g. What is the best CRM for a small marketing agency?'),
                    ]),
            ])
            ->afterValidation(function (Get $get, Set $set) {
                $brand = $this->brand();
                $result = $this->savePrompts($brand, collect($get('prompts') ?? []));
                $set('prompts', $this->promptRows($brand));

                if ($result['created'] > 0) {
                    Notification::make()
                        ->title("Added {$result['created']} prompts")
                        ->body($result['paused'] > 0 ? "{$result['paused']} were saved as paused because of the active-prompt limit." : null)
                        ->success()
                        ->send();
                }

                if ($brand->activePrompts()->count() === 0) {
                    $this->fail('Add at least one prompt', 'Tracking needs at least one question to ask the AI engines.');
                }

                app(Settings::class)->setSetupStep(8);
            });
    }

    /**
     * The brand's active prompts as rows, or one empty row to start with.
     *
     * @return array<int, array{id: ?int, text: ?string}>
     */
    protected function promptRows(?Brand $brand): array
    {
        $rows = $brand
            ? $brand->activePrompts()->orderBy('id')->get(['id', 'text'])->map(fn ($prompt) => ['id' => $prompt->getKey(), 'text' => $prompt->text])->all()
            : [];

        return $rows ?: [['id' => null, 'text' => null]];
    }

    /**
     * Sync the rows with the brand's active prompts. Changed or removed prompts
     * that already have answers are paused rather than edited or deleted, so
     * their history stays intact.
     *
     * @param  Collection<array-key, array{id?: ?int, text?: ?string}>  $rows
     * @return array{created: int, paused: int}
     */
    protected function savePrompts(Brand $brand, Collection $rows): array
    {
        $keep = [];
        $new = [];

        foreach ($rows as $row) {
            $text = TextHelper::squish((string) ($row['text'] ?? ''));
            $prompt = filled($row['id'] ?? null) ? $brand->prompts()->find($row['id']) : null;

            if ($text === '') {
                continue;
            }

            if ($prompt && TextHelper::hash($prompt->text) === TextHelper::hash($text)) {
                $keep[] = $prompt->getKey();
            } elseif ($prompt && ! $prompt->results()->exists()) {
                $prompt->update(['text' => $text]);
                $keep[] = $prompt->getKey();
            } else {
                $new[] = $text;
            }
        }

        foreach ($brand->activePrompts()->whereKeyNot($keep)->get() as $removed) {
            $removed->results()->exists()
                ? $removed->update(['status' => PromptStatus::Paused])
                : $removed->delete();
        }

        $result = $new === [] ? ['created' => 0, 'paused' => 0] : app(Importer::class)->prompts($brand, $new);

        return ['created' => $result['created'], 'paused' => $result['paused']];
    }

    protected function alertsStep(): Step
    {
        return Step::make('Alerts')
            ->icon('heroicon-o-bell-alert')
            ->description('Where to notify you')
            ->schema([
                Text::make('Alerts tell you when an engine pauses (bad key, no credits, outage), when the budget runs out, or when tracking stops running. These alerts are always sent.'),
                ...ManageSettings::alertComponents(),
            ])
            ->afterValidation(function (Get $get) {
                app(Settings::class)->set([
                    'alerts' => [
                        'database' => (bool) $get('alerts.database'),
                        'user_ids' => array_values($get('alerts.user_ids') ?? []),
                        'emails' => array_values($get('alerts.emails') ?? []),
                        'slack_webhook' => $get('alerts.slack_webhook'),
                    ],
                ]);

                app(Settings::class)->setSetupStep(9);
            });
    }

    protected function reviewStep(): Step
    {
        return Step::make('Start')
            ->icon('heroicon-o-rocket-launch')
            ->description('Review')
            ->schema([
                View::make('ai-visibility::setup.review')
                    ->viewData(fn () => $this->reviewData()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviewData(): array
    {
        $brand = Brand::query()->find($this->data['brand']['id'] ?? null);
        $engines = app(EngineManager::class)->enabled();
        $prompts = $brand?->activePrompts()->count() ?? 0;
        $samples = (int) app(Settings::class)->get('runs.samples', 1);
        $frequency = app(Settings::class)->get('runs.frequency', 'weekly');

        return [
            'brand' => $brand,
            'engines' => array_map(fn ($key) => app(EngineRegistry::class)->get($key)->label(), $engines),
            'prompts' => $prompts,
            'competitors' => $brand?->competitors()->count() ?? 0,
            'keywords' => $brand?->keywords()->count() ?? 0,
            'frequency' => RunFrequency::tryFrom((string) $frequency)?->getLabel() ?? $frequency,
            'monthly' => CostEstimator::format(CostEstimator::monthly($prompts, $engines, $samples, $frequency)),
            'budget' => app(Settings::class)->get('budget.monthly_usd'),
        ];
    }

    public function finish(): void
    {
        $this->form->getState();

        $settings = app(Settings::class);
        $settings->completeSetup();

        Notification::make()
            ->title('Setup complete')
            ->body('Tracking starts on the schedule you chose.')
            ->success()
            ->send();

        $brand = $this->brand(required: false);

        $this->redirect(
            ($brand ? AiVisibilityPlugin::pageUrl(BrandResource::class, 'edit', ['record' => $brand]) : null)
                ?? AiVisibilityPlugin::pageUrl(Overview::class)
                ?? AiVisibilityPlugin::pageUrl(BrandResource::class)
                ?? filament()->getUrl(),
        );
    }

    protected function brand(bool $required = true): ?Brand
    {
        $brand = Brand::query()->find($this->data['brand']['id'] ?? null);

        if (! $brand && $required) {
            $this->fail('Add your brand first', 'Go back to the Brand step.');
        }

        return $brand;
    }

    /**
     * Stop the wizard from moving on, with a message.
     */
    protected function fail(string $title, string $body): never
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();

        throw new Halt;
    }
}
