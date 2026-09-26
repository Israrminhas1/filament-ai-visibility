<?php

namespace IsrarMinhas\FilamentAiVisibility\Alerts;

use Illuminate\Support\Facades\Cache;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Models\SettingsRecord;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Always-on alerts when tracking silently stops: the queue worker (checked by
 * the scheduler) or the scheduler itself (checked when someone opens an AI
 * Visibility screen, since a stopped scheduler cannot report itself).
 */
class StallWatcher
{
    public function __construct(
        protected AlertNotifier $notifier,
    ) {}

    /**
     * Called by the scheduler: warns every tenant when the queue worker has stopped.
     */
    public function checkQueue(): bool
    {
        $last = Heartbeat::lastBeat(SystemHealth::queueHeartbeatName());
        $critical = (int) config('ai-visibility.health.queue_critical_after', 60);

        if ($last && $last->gt(now()->subMinutes($critical))) {
            return false;
        }

        $this->alertAll('queue', 'The AI Visibility queue worker has stopped', ($last ? 'No queued job has been processed since ' . $last->diffForHumans() . '.' : 'No queued job has ever been processed.') . ' Runs, analysis and discovery are waiting. Restart your queue worker (php artisan queue:work).');

        return true;
    }

    /**
     * Called when an AI Visibility screen is opened: warns when the scheduler has stopped.
     */
    public function checkScheduler(): void
    {
        $last = Heartbeat::lastBeat(SystemHealth::SCHEDULER);
        $critical = (int) config('ai-visibility.health.scheduler_critical_after', 60);

        // Never ran at all is handled by the setup wizard's system check.
        if (! $last || $last->gt(now()->subMinutes($critical))) {
            return;
        }

        $this->alert('scheduler', 'The Laravel scheduler has stopped', 'The scheduler last ran ' . $last->diffForHumans() . ', so no scheduled tracking is happening. Check the cron entry: * * * * * php artisan schedule:run');
    }

    protected function alertAll(string $what, string $title, string $body): void
    {
        $tenants = SettingsRecord::query()->withoutGlobalScopes()->pluck('tenant_id')->unique();

        foreach ($tenants as $tenantId) {
            Tenancy::as($tenantId, fn () => $this->alert($what, $title, $body));
        }
    }

    /**
     * At most one alert per problem, tenant and 6 hours.
     */
    protected function alert(string $what, string $title, string $body): void
    {
        $key = 'ai-visibility:stalled:' . $what . ':' . (Tenancy::currentId() ?? 'global');

        if (! Cache::add($key, true, now()->addHours(6))) {
            return;
        }

        $this->notifier->send(new Alert(
            title: $title,
            body: $body,
            level: 'danger',
            url: AiVisibilityPlugin::pageUrl(Health::class),
            urlLabel: 'Open Health',
            type: 'system_stalled',
        ));
    }
}
