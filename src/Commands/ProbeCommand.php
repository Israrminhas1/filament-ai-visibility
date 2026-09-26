<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\EngineState;
use IsrarMinhas\FilamentAiVisibility\Runs\BudgetGuard;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Brings paused engines back automatically where that is safe: after a
 * rate-limit pause ends, when an outage probe succeeds, when credits are
 * topped up, or when a new month frees the budget.
 */
class ProbeCommand extends Command
{
    protected $signature = 'ai-visibility:probe';

    protected $description = 'Check paused AI Visibility engines and resume those that work again';

    public function handle(): int
    {
        // Tenants with a paused engine, or a brand paused for its own budget.
        $tenants = EngineState::query()->withoutGlobalScopes()
            ->where('status', EngineStatus::Paused)
            ->pluck('tenant_id')
            ->merge(Brand::query()->withoutGlobalScopes()->where('paused_reason', 'budget')->pluck('tenant_id'))
            ->unique();

        // In a multi-tenant install, running as "no tenant" would act on every tenant's rows at once.
        if (app(EngineManager::class)->tenantIds() !== []) {
            $tenants = $tenants->reject(fn ($id) => $id === null);
        }

        foreach ($tenants as $tenantId) {
            Tenancy::as($tenantId, fn () => $this->probeTenant());
        }

        return self::SUCCESS;
    }

    protected function probeTenant(): void
    {
        $engines = app(EngineManager::class);

        app(BudgetGuard::class)->release();

        foreach ($engines->dueForProbe() as $engine) {
            $state = $engines->state($engine);

            if ($state->reason === PauseReason::Budget) {
                continue;
            }

            // Rate-limit pauses simply end; others need a successful test call.
            if ($state->reason === PauseReason::RateLimited) {
                $engines->resume($engine);
                $this->components->info("{$engine}: resumed after rate limiting.");

                continue;
            }

            $result = $engines->test($engine);

            if ($result->ok) {
                $engines->resume($engine);
                $this->components->info("{$engine}: resumed.");

                continue;
            }

            // The key itself is now the problem: that needs a person, not another probe.
            if ($result->reason && ! $result->reason->resumesAutomatically()) {
                $engines->pause($engine, $result->reason, $result->message);
                $this->components->warn("{$engine}: paused ({$result->reason->getLabel()}).");

                continue;
            }

            // Still failing: schedule the next check.
            $minutes = $state->reason === PauseReason::InsufficientCredits
                ? (int) config('ai-visibility.reliability.credits_probe_minutes', 360)
                : (int) min(config('ai-visibility.reliability.outage_pause_max_minutes', 240), max(15, $state->paused_at?->diffInMinutes(now()) ?? 15));

            $state->forceFill(['next_probe_at' => now()->addMinutes($minutes), 'message' => $result->message])->save();
            $this->components->warn("{$engine}: still paused ({$result->message}).");
        }
    }
}
