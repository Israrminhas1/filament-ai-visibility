<?php

namespace IsrarMinhas\FilamentAiVisibility;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use IsrarMinhas\FilamentAiVisibility\Commands\EnginesCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\HealthCommand;
use IsrarMinhas\FilamentAiVisibility\Commands\InstallCommand;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\AnthropicEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\GeminiEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\GrokEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\OpenAiEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\PerplexityEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Jobs\QueueHeartbeat;
use IsrarMinhas\FilamentAiVisibility\Listeners\SendEngineAlerts;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
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
            ])
            ->hasCommands([
                InstallCommand::class,
                HealthCommand::class,
                EnginesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(EngineRegistry::class, function () {
            $registry = new EngineRegistry;

            foreach ([OpenAiEngine::class, AnthropicEngine::class, GeminiEngine::class, GrokEngine::class, PerplexityEngine::class] as $engine) {
                $registry->register($engine);
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
    }

    public function packageBooted(): void
    {
        Event::listen(EnginePaused::class, [SendEngineAlerts::class, 'handlePaused']);
        Event::listen(EngineResumed::class, [SendEngineAlerts::class, 'handleResumed']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Heartbeats prove the scheduler and the queue worker are alive (see the Health page).
            $schedule->call(fn () => Heartbeat::beat(SystemHealth::SCHEDULER))
                ->everyMinute()
                ->name('ai-visibility:scheduler-heartbeat');

            $schedule->job(new QueueHeartbeat)
                ->everyFiveMinutes()
                ->name('ai-visibility:queue-heartbeat');
        });
    }
}
