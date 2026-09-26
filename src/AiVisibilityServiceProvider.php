<?php

namespace IsrarMinhas\FilamentAiVisibility;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use IsrarMinhas\FilamentAiVisibility\Commands\AlertsCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\DiscoverCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\SendReportsCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\EnginesCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\HealthCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\InstallCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\PollBatchesCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\ProbeCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\RedetectCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\RunCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\SweepRunsCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\SyncKeywordsCommand;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\AnthropicEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\GeminiEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\GoogleAiModeEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\GoogleAiOverviewEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\GrokEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\OpenAiEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\PerplexityEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Events\RunCompleted;
use IsrarMinhas\FilamentAiVisibility\Jobs\DiscoverCompetitorsJob;
use IsrarMinhas\FilamentAiVisibility\Jobs\EvaluateAlertsJob;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use IsrarMinhas\FilamentAiVisibility\Jobs\QueueHeartbeat;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry;
use IsrarMinhas\FilamentAiVisibility\Keywords\Sources\DataForSeoKeywords;
use IsrarMinhas\FilamentAiVisibility\Keywords\Sources\GoogleSearchConsole;
use IsrarMinhas\FilamentAiVisibility\Keywords\Sources\SerpApiPeopleAlsoAsk;
use IsrarMinhas\FilamentAiVisibility\Listeners\SendEngineAlerts;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Reports\Metrics;
use IsrarMinhas\FilamentAiVisibility\Runs\BudgetGuard;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Runs\RunProgress;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Pricing;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AiVisibilityServiceProvider extends PackageServiceProvider
{
    public static string $name = 'ai-visibility';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations([
                'create_ai_visibility_tables',
                'create_ai_visibility_tracking_tables',
                'create_ai_visibility_competitor_tables',
                'add_ai_visibility_analysis_columns',
                'create_ai_visibility_connections_table',
                'create_ai_visibility_automation_tables',
                'create_ai_visibility_batches_table',
                'add_ai_visibility_reliability_columns',
            ])
            ->hasCommands([
                InstallCommand::class,
                HealthCommand::class,
                EnginesCommand::class,
                RunCommand::class,
                ProbeCommand::class,
                DiscoverCommand::class,
                SyncKeywordsCommand::class,
                AlertsCommand::class,
                SendReportsCommand::class,
                PollBatchesCommand::class,
                SweepRunsCommand::class,
                RedetectCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(EngineRegistry::class, function () {
            $registry = new EngineRegistry;

            foreach ([OpenAiEngine::class, AnthropicEngine::class, GeminiEngine::class, GrokEngine::class, PerplexityEngine::class, GoogleAiOverviewEngine::class, GoogleAiModeEngine::class] as $engine) {
                $registry->register($engine);
            }

            return $registry;
        });

        $this->app->singleton(KeywordSourceRegistry::class, function () {
            $registry = new KeywordSourceRegistry;

            foreach ([SerpApiPeopleAlsoAsk::class, GoogleSearchConsole::class, DataForSeoKeywords::class] as $source) {
                $registry->register($source);
            }

            return $registry;
        });

        $this->app->singleton(Settings::class);
        $this->app->singleton(KeyResolver::class);
        $this->app->singleton(EngineManager::class);
        $this->app->singleton(Limits::class);
        $this->app->singleton(Importer::class);
        $this->app->singleton(SystemHealth::class);
        $this->app->singleton(AlertNotifier::class);
        $this->app->singleton(Pricing::class);
        $this->app->singleton(Spend::class);
        $this->app->singleton(RunPlanner::class);
        $this->app->singleton(RunProgress::class);
        $this->app->singleton(BudgetGuard::class);
        $this->app->singleton(Metrics::class);
        $this->app->singleton(Instructions::class);
        $this->app->singleton(HelperAi::class);
    }

    public function packageBooted(): void
    {
        // Widgets are Livewire components; register them by name so updates resolve on any panel.
        foreach ([
            Filament\Widgets\PausedEngines::class,
            Filament\Widgets\VisibilityStats::class,
            Filament\Widgets\VisibilityTrendChart::class,
            Filament\Widgets\ShareOfVoiceChart::class,
            Filament\Widgets\EngineVisibilityChart::class,
            Filament\Widgets\TopSources::class,
            Filament\Widgets\PromptMovers::class,
            Filament\Widgets\SourceCategoriesChart::class,
            Filament\Widgets\OwnPagesCited::class,
            Filament\Widgets\CompetitorLeaderboard::class,
            Filament\Widgets\EngineHeatmap::class,
            Filament\Widgets\BrandPerception::class,
            Filament\Widgets\HeadToHead::class,
            Filament\Widgets\Opportunities::class,
            Filament\Widgets\TopicPerformance::class,
        ] as $widget) {
            Livewire::component('ai-visibility.' . str(class_basename($widget))->kebab(), $widget);
        }

        // Long-running workers must see settings changed in the panel (kill switch, budgets…).
        Event::listen(JobProcessing::class, fn () => app(Settings::class)->flush());

        // After each run, look for competitors in the new answers.
        Event::listen(RunCompleted::class, function (RunCompleted $event) {
            $wanted = Tenancy::as($event->run->tenant_id, fn () => app(Settings::class)->get('discovery.enabled', true) || app(Settings::class)->get('analysis.enabled', true));

            if ($event->run->results_done > 0 && $wanted) {
                DiscoverCompetitorsJob::dispatch($event->run->brand_id, $event->run->tenant_id, $event->run->getKey());
            }

            // Check alert rules against the new answers.
            EvaluateAlertsJob::dispatch($event->run->brand_id, $event->run->tenant_id, $event->run->getKey());
        });

        Event::listen(EnginePaused::class, [SendEngineAlerts::class, 'handlePaused']);
        Event::listen(EngineResumed::class, [SendEngineAlerts::class, 'handleResumed']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Heartbeats prove the scheduler and the queue worker are alive (see the Health page).
            $schedule->call(fn () => Heartbeat::beat(SystemHealth::SCHEDULER))
                ->everyMinute()
                ->name('ai-visibility:scheduler-heartbeat');

            // One heartbeat per queue, so a queue without a worker is noticed.
            foreach (array_keys(SystemHealth::queues()) as $queue) {
                $schedule->job(new QueueHeartbeat($queue))
                    ->everyFiveMinutes()
                    ->name("ai-visibility:queue-heartbeat:{$queue}");
            }

            // Start due runs, and bring paused engines back when they work again.
            $schedule->command('ai-visibility:run --due')
                ->everyFifteenMinutes()
                ->withoutOverlapping()
                ->name('ai-visibility:run');

            // Re-score and re-classify competitors daily (stale classifications are refreshed).
            $schedule->command('ai-visibility:discover --queue')
                ->dailyAt('04:30')
                ->name('ai-visibility:discover');

            $schedule->command('ai-visibility:sync-keywords')
                ->dailyAt('05:00')
                ->name('ai-visibility:sync-keywords');

            // Alert rules daily; the queue watchdog every 10 minutes; reports when due.
            $schedule->command('ai-visibility:alerts')
                ->dailyAt('07:00')
                ->name('ai-visibility:alerts');

            $schedule->command('ai-visibility:alerts --watch')
                ->everyTenMinutes()
                ->name('ai-visibility:watch');

            $schedule->command('ai-visibility:send-reports')
                ->hourly()
                ->withoutOverlapping()
                ->name('ai-visibility:send-reports');

            $schedule->command('ai-visibility:probe')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->name('ai-visibility:probe');

            // Economy mode: collect finished batches.
            $schedule->command('ai-visibility:poll-batches')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->name('ai-visibility:poll-batches');

            // Close runs whose remaining answers were lost (e.g. a flushed queue).
            $schedule->command('ai-visibility:sweep-runs')
                ->hourly()
                ->withoutOverlapping()
                ->name('ai-visibility:sweep-runs');
        });
    }
}
