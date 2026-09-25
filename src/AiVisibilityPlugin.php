<?php

namespace IsrarMinhas\FilamentAiVisibility;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Overview;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\SourcesReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
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

    protected bool $setupWizard = true;

    /**
     * @var array<string, bool>
     */
    protected array $screens = [
        'overview' => true,
        'sources' => true,
        'brands' => true,
        'prompts' => true,
        'keywords' => true,
        'runs' => true,
        'answers' => true,
        'settings' => true,
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

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
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

    public function brands(bool $condition = true): static
    {
        return $this->screen('brands', $condition);
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

    public function withoutEngine(string $key): static
    {
        $this->removedEngines[] = $key;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $resources = array_keys(array_filter([
            BrandResource::class => $this->screens['brands'],
            PromptResource::class => $this->screens['prompts'],
            KeywordResource::class => $this->screens['keywords'],
            RunResource::class => $this->screens['runs'],
            ResultResource::class => $this->screens['answers'],
        ]));

        $pages = array_keys(array_filter([
            Setup::class => $this->setupWizard,
            Overview::class => $this->screens['overview'],
            SourcesReport::class => $this->screens['sources'],
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
    }

    protected function screen(string $screen, bool $condition): static
    {
        $this->screens[$screen] = $condition;

        return $this;
    }
}
