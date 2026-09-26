<?php

namespace IsrarMinhas\FilamentAiVisibility\Listeners;

use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Engine pause/resume alerts are always sent; they cannot be switched off.
 */
class SendEngineAlerts
{
    public function __construct(
        protected AlertNotifier $notifier,
        protected EngineRegistry $engines,
    ) {}

    public function handlePaused(EnginePaused $event): void
    {
        $label = $this->label($event->engine);

        Tenancy::as($event->tenantId, fn () => $this->notifier->send(new Alert(
            title: "{$label} paused: {$event->reason->getLabel()}",
            body: trim(($event->message && $event->message !== $event->reason->fix() ? $event->message . "\n\n" : '') . $event->reason->fix()),
            level: 'danger',
            url: AiVisibilityPlugin::pageUrl(Health::class),
            urlLabel: 'Open engine health',
            type: 'engine_paused',
            payload: ['engine' => $event->engine, 'reason' => $event->reason->value],
        )));
    }

    public function handleResumed(EngineResumed $event): void
    {
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
}
