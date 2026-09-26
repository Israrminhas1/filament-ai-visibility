<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\EngineState;
use IsrarMinhas\FilamentAiVisibility\Models\ProviderKey;
use IsrarMinhas\FilamentAiVisibility\Models\SettingsRecord;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use RuntimeException;

/**
 * Which engines are enabled, usable or paused, and why.
 */
class EngineManager
{
    public function __construct(
        protected EngineRegistry $registry,
        protected KeyResolver $keys,
        protected Settings $settings,
    ) {}

    public function registry(): EngineRegistry
    {
        return $this->registry;
    }

    /**
     * Engines switched on in Settings (or for a brand, its override).
     *
     * @return array<string>
     */
    public function enabled(?Brand $brand = null): array
    {
        $enabled = $brand ? $brand->setting('engines.enabled', []) : $this->settings->get('engines.enabled', []);

        return array_values(array_filter((array) $enabled, fn ($key) => $this->registry->has($key)));
    }

    /**
     * Enabled engines that can be called right now: they have a key and are not paused.
     *
     * @return array<string>
     */
    public function usable(?Brand $brand = null): array
    {
        return array_values(array_filter($this->enabled($brand), fn (string $engine) => $this->isUsable($engine)));
    }

    public function isUsable(string $engine): bool
    {
        if (! $this->keys->has($engine)) {
            // A manual or budget pause is kept, so adding a key later does not lift it.
            if (! $this->waitsForPerson($this->state($engine))) {
                $this->pause($engine, PauseReason::MissingKey);
            }

            return false;
        }

        $state = $this->state($engine);

        if ($state->status === EngineStatus::Paused && $state->reason === PauseReason::MissingKey) {
            // A key has been added since the engine was paused for having none.
            $this->resume($engine);

            return true;
        }

        return $state->isUsable();
    }

    public function state(string $engine): EngineState
    {
        if ($state = $this->findState($engine)) {
            return $state;
        }

        $create = function () use ($engine): EngineState {
            try {
                return $this->findState($engine) ?? EngineState::query()->create(['engine' => $engine]);
            } catch (UniqueConstraintViolationException) {
                // Another process created it first.
                return $this->findState($engine) ?? throw new RuntimeException("Could not load the state of engine [{$engine}].");
            }
        };

        // Most databases don't enforce a unique index on a NULL tenant_id, so the
        // single-tenant row is created under a lock instead.
        if (Tenancy::currentId() === null) {
            try {
                return Cache::lock("ai-visibility:engine-state:{$engine}", 10)->block(5, $create);
            } catch (LockTimeoutException) {
                return $create();
            }
        }

        return $create();
    }

    /**
     * The engine's state without creating it, for display paths. Null means
     * the engine has never been used (and so counts as active).
     */
    public function existingState(string $engine): ?EngineState
    {
        return $this->findState($engine);
    }

    protected function findState(string $engine): ?EngineState
    {
        // The oldest row wins, should a duplicate ever exist.
        return EngineState::query()->where('engine', $engine)->orderBy('id')->first();
    }

    /**
     * Tenants with settings, keys or engine state, for console commands that act
     * on each tenant in turn. Empty for a single-tenant install.
     *
     * @return array<int|string>
     */
    public function tenantIds(): array
    {
        if (! Tenancy::enabled()) {
            return [];
        }

        return collect([SettingsRecord::class, ProviderKey::class, EngineState::class])
            ->flatMap(fn (string $model) => $model::query()->withoutGlobalScopes()->whereNotNull('tenant_id')->distinct()->pluck('tenant_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a --tenant option names a tenant this install knows about.
     */
    public function isKnownTenant(int | string $tenantId): bool
    {
        return in_array((string) $tenantId, array_map('strval', $this->tenantIds()), true);
    }

    /**
     * Run a callback for the given tenant, the current one, or every known tenant.
     * A multi-tenant install never runs as "no tenant", so no rows are created
     * without a tenant_id.
     *
     * @param  callable(int|string|null): void  $callback
     */
    public function forEachTenant(int | string | null $tenantId, callable $callback): void
    {
        if ($tenantId !== null && $tenantId !== '') {
            Tenancy::as($tenantId, fn () => $callback($tenantId));

            return;
        }

        if (($current = Tenancy::currentId()) !== null) {
            $callback($current);

            return;
        }

        $tenants = $this->tenantIds();

        if ($tenants === []) {
            $callback(null);

            return;
        }

        foreach ($tenants as $tenant) {
            Tenancy::as($tenant, fn () => $callback($tenant));
        }
    }

    public function model(string $engine, ?Brand $brand = null): string
    {
        $models = $brand ? $brand->setting('engines.models', []) : $this->settings->get('engines.models', []);

        return ($models[$engine] ?? null) ?: $this->registry->get($engine)->defaultTrackingModel();
    }

    /**
     * The engine and model for helper features (analysis, classification, generation).
     * In "auto" mode this is the first usable engine in the configured order, so a
     * single key is enough for everything.
     *
     * @return array{engine: Engine, model: string}|null
     */
    public function helper(): ?array
    {
        $configured = $this->settings->get('helpers.engine', 'auto');

        if ($configured !== 'auto') {
            if (! $this->registry->has($configured) || ! $this->registry->get($configured)->supportsCompletion() || ! $this->isUsable($configured)) {
                return null;
            }

            $engine = $this->registry->get($configured);

            return ['engine' => $engine, 'model' => $this->settings->get('helpers.model') ?: $engine->defaultHelperModel()];
        }

        $order = array_unique([...config('ai-visibility.helper_engine_order', []), ...array_keys($this->registry->all())]);

        foreach ($order as $key) {
            if ($this->registry->has($key) && $this->registry->get($key)->supportsCompletion() && $this->keys->has($key) && $this->isUsable($key)) {
                $engine = $this->registry->get($key);

                return ['engine' => $engine, 'model' => $engine->defaultHelperModel()];
            }
        }

        return null;
    }

    public function test(string $engine, ?string $apiKey = null): KeyTestResult
    {
        $apiKey ??= $this->keys->resolve($engine);

        if (blank($apiKey)) {
            return KeyTestResult::failed(PauseReason::MissingKey);
        }

        return $this->registry->get($engine)->testKey($apiKey);
    }

    /**
     * Test the engine's key and resume it if the test passes.
     */
    public function testAndResume(string $engine): KeyTestResult
    {
        $result = $this->test($engine);

        if ($result->ok) {
            $this->resume($engine);
        } else {
            $this->pause($engine, $result->reason ?? PauseReason::ProviderOutage, $result->message);
        }

        return $result;
    }

    /**
     * A real answer came back. It only ends a pause that would have ended on its
     * own (outage, rate limit, credits); manual, budget and key pauses stay.
     */
    public function recordSuccess(string $engine): void
    {
        $state = $this->state($engine);

        if ($state->status === EngineStatus::Paused) {
            if ($state->reason?->resumesAutomatically() ?? true) {
                // A healthy call resets the outage back-off.
                $state->forceFill(['last_error' => null])->save();
                $this->resume($engine);
            } else {
                $state->forceFill(['last_success_at' => now()])->save();
            }

            return;
        }

        $state->fill([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
            // A healthy call resets the outage back-off.
            'last_error' => null,
            'status' => $state->status === EngineStatus::Degraded && $state->resume_after?->isPast() ? EngineStatus::Active : $state->status,
        ])->save();
    }

    /**
     * Decide what a failed call means for the whole engine.
     *
     * - Key, credit and model problems pause immediately.
     * - Rate limits slow the engine down, and pause it after repeated 429s.
     * - Outages trip a circuit breaker after repeated failures, with growing pauses.
     * - Anything else only fails that one request.
     */
    public function recordFailure(string $engine, ?PauseReason $reason, ?string $message = null, ?int $retryAfter = null): void
    {
        $state = $this->state($engine);
        $config = config('ai-visibility.reliability');
        $previousReason = $state->last_error['reason'] ?? null;

        // Failures are counted per reason: a 429 after two timeouts is the first rate limit.
        $failures = $state->consecutive_failures > 0 && $previousReason === $reason?->value
            ? $state->consecutive_failures + 1
            : 1;

        $state->fill([
            'consecutive_failures' => $failures,
            'last_error_at' => now(),
            // The outage back-off counter is kept; only a real answer resets it.
            'last_error' => array_filter([
                'reason' => $reason?->value,
                'message' => $message,
                'outage_pauses' => $state->last_error['outage_pauses'] ?? null,
            ], fn ($value) => $value !== null),
        ])->save();

        // A pause that waits for a person (manual, budget, key, model) is never
        // replaced by one that ends on its own: late results from in-flight jobs
        // or batches would otherwise let the probe resume it.
        if ($this->waitsForPerson($state)) {
            return;
        }

        $pausedFor = fn (PauseReason $pause): bool => $state->status === EngineStatus::Paused && $state->reason === $pause;

        match ($reason) {
            PauseReason::InvalidKey, PauseReason::MissingKey, PauseReason::ModelUnavailable => $this->pause($engine, $reason, $message),

            PauseReason::InsufficientCredits => $this->pause($engine, $reason, $message, probeAt: $pausedFor($reason) && $state->next_probe_at?->isFuture()
                ? $state->next_probe_at
                : now()->addMinutes((int) $config['credits_probe_minutes'])),

            PauseReason::RateLimited => match (true) {
                $failures >= (int) $config['rate_limit_threshold'] => $this->pause($engine, $reason, $message, resumeAt: now()->addSeconds(max($retryAfter ?? 0, 60 * (int) $config['degraded_minutes']))),
                // Slowing down never lifts a pause.
                $state->status === EngineStatus::Paused => null,
                default => $state->fill([
                    'status' => EngineStatus::Degraded,
                    'resume_after' => now()->addMinutes((int) $config['degraded_minutes']),
                ])->save(),
            },

            // One outage is one pause: further failures while paused for it (in-flight
            // requests, late batch results) do not grow the back-off again.
            PauseReason::ProviderOutage => $failures >= (int) $config['failure_threshold'] && ! $pausedFor($reason)
                ? $this->pause($engine, $reason, $message, probeAt: now()->addMinutes($this->outagePauseMinutes($state)))
                : null,

            default => null,
        };
    }

    /**
     * Paused for a reason that does not end on its own (manual, budget, key, model).
     */
    protected function waitsForPerson(EngineState $state): bool
    {
        return $state->status === EngineStatus::Paused && $state->reason !== null && ! $state->reason->resumesAutomatically();
    }

    /**
     * 15, 30, 60… minutes, doubling for each outage pause in a row, up to the maximum.
     */
    protected function outagePauseMinutes(EngineState $state): int
    {
        $config = config('ai-visibility.reliability');
        $previous = (int) ($state->last_error['outage_pauses'] ?? 0);

        $state->forceFill(['last_error' => [...($state->last_error ?? []), 'outage_pauses' => $previous + 1]])->save();

        return (int) min($config['outage_pause_minutes'] * (2 ** $previous), $config['outage_pause_max_minutes']);
    }

    /**
     * Requests per minute allowed right now (halved while degraded by rate limits).
     */
    public function requestsPerMinute(string $engine): int
    {
        $rpm = max(1, (int) $this->settings->get('engines.requests_per_minute', 20));

        return $this->state($engine)->status === EngineStatus::Degraded ? max(1, intdiv($rpm, 2)) : $rpm;
    }

    /**
     * Paused engines whose automatic check is due (credits, outages, rate limits, budget).
     *
     * @return array<string>
     */
    public function dueForProbe(): array
    {
        return EngineState::query()
            ->where('status', EngineStatus::Paused)
            ->whereIn('reason', array_map(fn (PauseReason $reason) => $reason->value, [
                PauseReason::InsufficientCredits, PauseReason::ProviderOutage, PauseReason::RateLimited, PauseReason::Budget,
            ]))
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNotNull('next_probe_at')->where('next_probe_at', '<=', now()))
                ->orWhere(fn ($q) => $q->whereNotNull('resume_after')->where('resume_after', '<=', now()))
                ->orWhere('reason', PauseReason::Budget->value))
            ->pluck('engine')
            ->all();
    }

    /**
     * Without explicit times, a reason that recovers on its own keeps its next
     * automatic check (or gets one), so the engine is never left stuck.
     */
    public function pause(string $engine, PauseReason $reason, ?string $message = null, mixed $probeAt = null, mixed $resumeAt = null): void
    {
        $state = $this->state($engine);

        $alreadyPaused = $state->status === EngineStatus::Paused && $state->reason === $reason;

        if ($probeAt === null && $resumeAt === null && $reason->resumesAutomatically()) {
            [$probeAt, $resumeAt] = $alreadyPaused && ($state->next_probe_at?->isFuture() || $state->resume_after?->isFuture())
                ? [$state->next_probe_at, $state->resume_after]
                : $this->schedule($state, $reason);
        }

        $state->fill([
            'status' => EngineStatus::Paused,
            'reason' => $reason,
            'message' => $message ?? $reason->fix(),
            'paused_at' => $alreadyPaused ? $state->paused_at : now(),
            'next_probe_at' => $probeAt,
            'resume_after' => $resumeAt,
        ])->save();

        // One event per pause episode, not per failed request.
        if (! $alreadyPaused) {
            EnginePaused::dispatch($engine, $reason, $state->message);
        }
    }

    /**
     * When a paused engine is checked next: [next_probe_at, resume_after].
     *
     * @return array{0: mixed, 1: mixed}
     */
    protected function schedule(EngineState $state, PauseReason $reason): array
    {
        $config = config('ai-visibility.reliability', []);

        return match ($reason) {
            PauseReason::InsufficientCredits => [now()->addMinutes((int) ($config['credits_probe_minutes'] ?? 360)), null],
            PauseReason::RateLimited => [null, now()->addMinutes((int) ($config['degraded_minutes'] ?? 5))],
            PauseReason::ProviderOutage => [now()->addMinutes($this->outagePauseMinutes($state)), null],
            default => [null, null],
        };
    }

    public function resume(string $engine): void
    {
        $state = $this->state($engine);
        $wasPaused = $state->status === EngineStatus::Paused;

        $state->fill([
            'status' => EngineStatus::Active,
            'reason' => null,
            'message' => null,
            'consecutive_failures' => 0,
            'paused_at' => null,
            'resume_after' => null,
            'next_probe_at' => null,
            'last_success_at' => now(),
        ])->save();

        if ($wasPaused) {
            EngineResumed::dispatch($engine);
        }
    }
}
