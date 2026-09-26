<?php

namespace IsrarMinhas\FilamentAiVisibility\Listeners;

use Illuminate\Support\Facades\Cache;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Engine pause/resume alerts are always sent; they cannot be switched off.
 * A flapping engine sends at most one "paused" alert per reason every few
 * hours, and a "resumed" alert only follows a "paused" alert that went out.
 * Sending a "resumed" alert clears the throttle, so a pause that follows it
 * is always reported and "resumed" is never the last word on a paused engine.
 *
 * The bookkeeping lives in the cache: if the cache is cleared or evicted, an
 * alert may be repeated or a "resumed" alert skipped, but never worse.
 */
class SendEngineAlerts
{
    public function __construct(
        protected AlertNotifier $notifier,
        protected EngineRegistry $engines,
    ) {}

    public function handlePaused(EnginePaused $event): void
    {
        $hours = max(0, (int) config('ai-visibility.alerts.engine_alert_throttle_hours', 6));

        if ($hours > 0 && ! Cache::add($this->cacheKey($event->tenantId, $event->engine, 'paused:' . $event->reason->value), true, now()->addHours($hours))) {
            return;
        }

        Cache::put($this->cacheKey($event->tenantId, $event->engine, 'open'), true, now()->addDays(30));

        $label = $this->label($event->engine);

        // Key problems are fixed in Settings, where the keys are; everything else on Health.
        $settingsUrl = in_array($event->reason, [PauseReason::MissingKey, PauseReason::InvalidKey, PauseReason::InsufficientCredits], true)
            ? ManageSettings::enginesUrl()
            : null;

        Tenancy::as($event->tenantId, fn () => $this->notifier->send(new Alert(
            title: "{$label} paused: {$event->reason->getLabel()}",
            body: trim(($event->message && $event->message !== $event->reason->fix() ? $event->message . "\n\n" : '') . $event->reason->fix()),
            level: 'danger',
            url: $settingsUrl ?? AiVisibilityPlugin::pageUrl(Health::class),
            urlLabel: $settingsUrl ? 'Fix the API key in Settings' : 'Open engine health',
            type: 'engine_paused',
            payload: ['engine' => $event->engine, 'reason' => $event->reason->value],
        )));
    }

    public function handleResumed(EngineResumed $event): void
    {
        // Nobody was told about this pause, so there is nothing to follow up.
        if (! Cache::pull($this->cacheKey($event->tenantId, $event->engine, 'open'))) {
            return;
        }

        foreach (PauseReason::cases() as $reason) {
            Cache::forget($this->cacheKey($event->tenantId, $event->engine, 'paused:' . $reason->value));
        }

        $label = $this->label($event->engine);

        Tenancy::as($event->tenantId, fn () => $this->notifier->send(new Alert(
            title: "{$label} resumed",
            body: "{$label} is working again. Tracking on this engine continues with the next run.",
            level: 'success',
            type: 'engine_resumed',
            payload: ['engine' => $event->engine],
        )));
    }

    protected function label(string $engine): string
    {
        return $this->engines->has($engine) ? $this->engines->get($engine)->label() : $engine;
    }

    protected function cacheKey(int | string | null $tenantId, string $engine, string $suffix): string
    {
        return 'ai-visibility:engine-alert:' . ($tenantId ?? '-') . ":{$engine}:{$suffix}";
    }
}
