<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Runs\BudgetGuard;

/**
 * Helper AI calls (classification, extraction, suggestions, generation) on
 * the helper engine: same pausing, budgets and cost tracking as tracking.
 */
class HelperAi
{
    public function __construct(
        protected EngineManager $engines,
        protected KeyResolver $keys,
        protected Pricing $pricing,
        protected Spend $spend,
        protected Settings $settings,
    ) {}

    public function available(): bool
    {
        return $this->engines->helper() !== null;
    }

    /**
     * @return array<mixed>
     *
     * @throws HelperUnavailable when no engine can be used, the budget is spent, or the reply is not JSON.
     */
    public function json(string $purpose, string $prompt, ?Brand $brand = null, ?string $system = null, int $maxTokens = 4096): array
    {
        $response = $this->complete($purpose, $prompt, $brand, $system, $maxTokens, json: true);
        $data = $response->json();

        if ($data === null) {
            throw new HelperUnavailable('The AI helper did not return valid JSON.');
        }

        return $data;
    }

    /**
     * @throws HelperUnavailable
     */
    public function complete(string $purpose, string $prompt, ?Brand $brand = null, ?string $system = null, int $maxTokens = 4096, bool $json = false): CompletionResponse
    {
        if ($this->settings->killSwitch()) {
            throw new HelperUnavailable('"Pause everything" is on in Settings.');
        }

        if ($brand ? app(BudgetGuard::class)->blocks($brand) : ($this->settings->get('budget.stop_at_budget', true) && $this->spend->overBudget())) {
            throw new HelperUnavailable('The monthly budget is used up.');
        }

        $helper = $this->engines->helper();

        if (! $helper) {
            throw new HelperUnavailable('No AI engine is available for helper features. Add a working API key, or check the engine chosen under Settings → AI helpers.');
        }

        $engine = $helper['engine'];

        try {
            $response = $engine->complete(new CompletionRequest(
                prompt: $prompt,
                model: $helper['model'],
                apiKey: (string) $this->keys->resolve($engine->key()),
                json: $json,
                maxTokens: $maxTokens,
                system: $system,
            ));
        } catch (EngineRequestFailed $e) {
            $this->engines->recordFailure($engine->key(), $e->reason, $e->getMessage(), $e->retryAfter);

            $paused = $this->engines->state($engine->key())->status === EngineStatus::Paused;

            throw new HelperUnavailable($paused ? "{$engine->label()} was paused: {$e->getMessage()}" : $e->getMessage(), previous: $e);
        }

        $this->engines->recordSuccess($engine->key());

        $cost = $this->pricing->cost($engine->key(), $response->model, $response->inputTokens, $response->outputTokens, $response->searches);
        $this->spend->record($engine->key(), $response->model, $purpose, $response->inputTokens, $response->outputTokens, $response->searches, $cost, $brand);

        return $response;
    }
}
