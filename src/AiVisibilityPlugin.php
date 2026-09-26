<?php

namespace IsrarMinhas\FilamentAiVisibility;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\CompetitorsReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\HeadToHeadReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\OpportunitiesReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Overview;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\SourcesReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\TopicsReport;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertEventResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Http\Middleware\RedirectToSetup;
use Throwable;

class AiVisibilityPlugin implements Plugin
{
    protected ?string $navigationGroup = 'AI Visibility';

    protected int $navigationSort = 0;

    protected bool $navigationGroups = true;

    protected ?Closure $authorizeUsing = null;

    protected Closure | bool $canManageSettings = true;

    protected ?Closure $alertRecipientsQuery = null;

    protected bool $setupWizard = true;

    /**
     * @var array<string, bool>
     */
    protected array $screens = [
        'overview' => true,
        'sources' => true,
        'competitorReports' => true,
        'brands' => true,
        'discovered' => true,
        'prompts' => true,
        'keywords' => true,
        'keywordSources' => true,
        'topics' => true,
        'runs' => true,
        'answers' => true,
        'settings' => true,
        'alerts' => true,
        'scheduledReports' => true,
        'health' => true,
    ];

    /**
     * @var array<class-string<Engine>|Engine>
     */
    protected array $extraEngines = [];

    /**
     * @var array<string>
     */
    protected array $removedEngines = [];

    /**
     * @var array<class-string<KeywordSource>|KeywordSource>
     */
    protected array $keywordSources = [];

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public static function current(): ?static
    {
        try {
            $panel = Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            return null;
        }

        $id = app(static::class)->getId();

        return $panel?->hasPlugin($id) ? $panel->getPlugin($id) : null;
    }

    /**
     * URL of a plugin page or resource on the current panel, or null when it is not registered there.
     */
    public static function pageUrl(string $class, string $page = 'index', array $parameters = []): ?string
    {
        try {
            return method_exists($class, 'getPages') ? $class::getUrl($page, $parameters) : $class::getUrl($parameters);
        } catch (Throwable) {
            return null;
        }
    }

    public function getId(): string
    {
        return 'ai-visibility';
    }

    /**
     * Put every screen in one navigation group (null for no group).
     */
    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;
        $this->navigationGroups = false;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    /**
     * Split the screens into reports, tracking and admin groups (on by default).
     */
    public function navigationGroups(bool $condition = true): static
    {
        $this->navigationGroups = $condition;

        return $this;
    }

    public function hasNavigationGroups(): bool
    {
        return $this->navigationGroups && $this->navigationGroup !== null;
    }

    /**
     * The navigation group for an area: "reports", "tracking" or "admin".
     */
    public function getNavigationGroupFor(string $area): ?string
    {
        if (! $this->hasNavigationGroups()) {
            return $this->navigationGroup;
        }

        return match ($area) {
            'tracking' => $this->navigationGroup . ' · Tracking',
            'admin' => $this->navigationGroup . ' · Admin',
            default => $this->navigationGroup,
        };
    }

    /**
     * Who can open AI Visibility at all. Receives the current user.
     */
    public function authorizeUsing(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Who can change settings, API keys, budgets and engine states, and run setup.
     */
    public function canManageSettings(Closure | bool $condition = true): static
    {
        $this->canManageSettings = $condition;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return $this->authorizeUsing === null || (bool) ($this->authorizeUsing)(static::user());
    }

    public function canManage(): bool
    {
        if (! $this->isAuthorized()) {
            return false;
        }

        return $this->canManageSettings instanceof Closure
            ? (bool) ($this->canManageSettings)(static::user())
            : $this->canManageSettings;
    }

    /**
     * Whether the current user can manage settings. True when the plugin is not on the current panel.
     */
    public static function userCanManage(): bool
    {
        return static::current()?->canManage() ?? true;
    }

    /**
     * Limit the users offered as alert recipients. Receives the users query and the
     * current Filament tenant (or null), and returns the query.
     *
     * @param  ?Closure(\Illuminate\Database\Eloquent\Builder, ?\Illuminate\Database\Eloquent\Model): mixed  $callback
     */
    public function alertRecipientsQuery(?Closure $callback): static
    {
        $this->alertRecipientsQuery = $callback;

        return $this;
    }

    public function getAlertRecipientsQuery(): ?Closure
    {
        return $this->alertRecipientsQuery;
    }

    protected static function user(): mixed
    {
        try {
            return Filament::auth()->user();
        } catch (Throwable) {
            return auth()->user();
        }
    }

    public function navigationSort(int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): int
    {
        return $this->navigationSort;
    }

    /**
     * Skip the setup wizard, e.g. when everything is configured in code.
     */
    public function withoutSetupWizard(bool $condition = true): static
    {
        $this->setupWizard = ! $condition;

        return $this;
    }

    public function hasSetupWizard(): bool
    {
        return $this->setupWizard;
    }

    public function overview(bool $condition = true): static
    {
        return $this->screen('overview', $condition);
    }

    public function sourcesReport(bool $condition = true): static
    {
        return $this->screen('sources', $condition);
    }

    /**
     * The Competitors, Head-to-head and Opportunities reports.
     */
    public function competitorReports(bool $condition = true): static
    {
        return $this->screen('competitorReports', $condition);
    }

    public function brands(bool $condition = true): static
    {
        return $this->screen('brands', $condition);
    }

    public function discovered(bool $condition = true): static
    {
        return $this->screen('discovered', $condition);
    }

    public function prompts(bool $condition = true): static
    {
        return $this->screen('prompts', $condition);
    }

    public function keywords(bool $condition = true): static
    {
        return $this->screen('keywords', $condition);
    }

    public function runs(bool $condition = true): static
    {
        return $this->screen('runs', $condition);
    }

    public function answers(bool $condition = true): static
    {
        return $this->screen('answers', $condition);
    }

    public function alerts(bool $condition = true): static
    {
        return $this->screen('alerts', $condition);
    }

    public function scheduledReports(bool $condition = true): static
    {
        return $this->screen('scheduledReports', $condition);
    }

    public function settingsPage(bool $condition = true): static
    {
        return $this->screen('settings', $condition);
    }

    public function healthPage(bool $condition = true): static
    {
        return $this->screen('health', $condition);
    }

    /**
     * Register an additional engine.
     *
     * @param  class-string<Engine>|Engine  $engine
     */
    public function engine(string | Engine $engine): static
    {
        $this->extraEngines[] = $engine;

        return $this;
    }

    /**
     * Register an additional keyword source.
     *
     * @param  class-string<KeywordSource>|KeywordSource  $source
     */
    public function keywordSource(string | KeywordSource $source): static
    {
        $this->keywordSources[] = $source;

        return $this;
    }

    public function keywordSourcesScreen(bool $condition = true): static
    {
        return $this->screen('keywordSources', $condition);
    }

    public function topicsReport(bool $condition = true): static
    {
        return $this->screen('topics', $condition);
    }

    public function withoutEngine(string $key): static
    {
        $this->removedEngines[] = $key;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $resources = array_keys(array_filter([
            BrandResource::class => $this->screens['brands'],
            CandidateResource::class => $this->screens['discovered'],
            PromptResource::class => $this->screens['prompts'],
            KeywordResource::class => $this->screens['keywords'],
            ConnectionResource::class => $this->screens['keywordSources'],
            AlertEventResource::class => $this->screens['alerts'],
            AlertRuleResource::class => $this->screens['alerts'],
            ReportScheduleResource::class => $this->screens['scheduledReports'],
            RunResource::class => $this->screens['runs'],
            ResultResource::class => $this->screens['answers'],
        ]));

        $pages = array_keys(array_filter([
            Setup::class => $this->setupWizard,
            Overview::class => $this->screens['overview'],
            SourcesReport::class => $this->screens['sources'],
            CompetitorsReport::class => $this->screens['competitorReports'],
            HeadToHeadReport::class => $this->screens['competitorReports'],
            OpportunitiesReport::class => $this->screens['competitorReports'],
            TopicsReport::class => $this->screens['topics'],
            ManageSettings::class => $this->screens['settings'],
            Health::class => $this->screens['health'],
        ]));

        $panel
            ->resources($resources)
            ->pages($pages)
            ->middleware([RedirectToSetup::class]);
    }

    public function boot(Panel $panel): void
    {
        $registry = app(EngineRegistry::class);

        foreach ($this->extraEngines as $engine) {
            $registry->register($engine);
        }

        foreach ($this->removedEngines as $key) {
            $registry->forget($key);
        }

        foreach ($this->keywordSources as $source) {
            app(KeywordSourceRegistry::class)->register($source);
        }
    }

    protected function screen(string $screen, bool $condition): static
    {
        $this->screens[$screen] = $condition;

        return $this;
    }
}
