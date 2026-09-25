<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\EngineState;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

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
            $this->pause($engine, PauseReason::MissingKey);

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
        return EngineState::query()->firstOrCreate(['engine' => $engine]);
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
            if (! $this->registry->has($configured) || ! $this->isUsable($configured)) {
                return null;
            }

            $engine = $this->registry->get($configured);

            return ['engine' => $engine, 'model' => $this->settings->get('helpers.model') ?: $engine->defaultHelperModel()];
        }

        $order = array_unique([...config('ai-visibility.helper_engine_order', []), ...array_keys($this->registry->all())]);

        foreach ($order as $key) {
            if ($this->registry->has($key) && $this->keys->has($key) && $this->isUsable($key)) {
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

    public function pause(string $engine, PauseReason $reason, ?string $message = null): void
    {
        $state = $this->state($engine);

        $alreadyPaused = $state->status === EngineStatus::Paused && $state->reason === $reason;

        $state->fill([
            'status' => EngineStatus::Paused,
            'reason' => $reason,
            'message' => $message ?? $reason->fix(),
            'paused_at' => $alreadyPaused ? $state->paused_at : now(),
        ])->save();

        // One event per pause episode, not per failed request.
        if (! $alreadyPaused) {
            EnginePaused::dispatch($engine, $reason, $state->message);
        }
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
