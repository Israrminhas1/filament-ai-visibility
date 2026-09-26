<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyTestResult;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use Throwable;

abstract class HttpEngine implements Engine
{
    public function defaultTrackingModel(): string
    {
        return config("ai-visibility.engines.{$this->key()}.tracking_model");
    }

    public function defaultHelperModel(): string
    {
        return config("ai-visibility.engines.{$this->key()}.helper_model");
    }

    public function suggestedModels(): array
    {
        return config("ai-visibility.engines.{$this->key()}.models", []);
    }

    public function aiMonitorProviders(): array
    {
        return [$this->key()];
    }

    public function credentialKey(): string
    {
        return $this->key();
    }

    public function supportsCompletion(): bool
    {
        return true;
    }

    public function testKey(string $apiKey): KeyTestResult
    {
        try {
            $response = $this->sendKeyTest($this->http($apiKey));
        } catch (ConnectionException $e) {
            return KeyTestResult::failed(PauseReason::ProviderOutage, 'could not connect to the provider');
        } catch (Throwable $e) {
            return KeyTestResult::failed(PauseReason::ProviderOutage, $e->getMessage());
        }

        if ($response->successful()) {
            return KeyTestResult::ok('Connected', $this->modelsFromKeyTest($response));
        }

        return KeyTestResult::failed(
            static::classifyFailure($response) ?? PauseReason::ProviderOutage,
            $this->errorMessage($response),
        );
    }

    public function ask(EngineRequest $request): EngineResponse
    {
        return $this->parseAnswer($this->send(fn () => $this->sendAsk($this->http($request->apiKey), $request)), $request);
    }

    /**
     * Send a request, turning connection problems and error responses into EngineRequestFailed.
     *
     * @param  callable(): Response  $send
     */
    protected function send(callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw EngineRequestFailed::unreachable('Could not connect to ' . $this->label() . ': ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw EngineRequestFailed::fromResponse($response, $this->label() . ': ' . $this->errorMessage($response));
        }

        return $response;
    }

    /**
     * Send a batch status or results request. A 404 there means the batch or its
     * file is gone (expired, deleted), which fails that poll, not the engine.
     *
     * @param  callable(): Response  $send
     */
    protected function sendBatchRequest(callable $send): Response
    {
        try {
            return $this->send($send);
        } catch (EngineRequestFailed $e) {
            if ($e->status === 404) {
                throw new EngineRequestFailed($e->getMessage(), null, $e->retryAfter, $e->status);
            }

            throw $e;
        }
    }

    /**
     * Send a tracked prompt with web search enabled.
     */
    abstract protected function sendAsk(PendingRequest $http, EngineRequest $request): Response;

    abstract protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse;

    /**
     * Make the cheapest request that proves the key works.
     */
    abstract protected function sendKeyTest(PendingRequest $request): Response;

    /**
     * An HTTP client authenticated with the key.
     */
    abstract protected function http(string $apiKey): PendingRequest;

    /**
     * @return array<string>
     */
    protected function modelsFromKeyTest(Response $response): array
    {
        return collect($response->json('data', []))->pluck('id')->filter()->sort()->values()->all();
    }

    /**
     * Map a failed provider response to the reason the engine should pause.
     * Returns null for errors that are specific to one request.
     */
    public static function classifyFailure(Response $response): ?PauseReason
    {
        $status = $response->status();

        if ($status >= 400 && $status < 500 && ($reason = static::classifyErrorFields($response))) {
            return $reason;
        }

        $body = strtolower($response->body());

        $billing = str_contains($body, 'insufficient_quota')
            || str_contains($body, 'billing')
            || str_contains($body, 'credit balance')
            || str_contains($body, 'credits')
            || str_contains($body, 'payment required')
            || str_contains($body, 'exceeded your current quota');

        // The chosen model can't search the web, or doesn't exist: every request would fail the same way.
        $badModel = (str_contains($body, 'not supported') || str_contains($body, 'unsupported') || str_contains($body, 'not available'))
                && (str_contains($body, 'tool') || str_contains($body, 'search') || str_contains($body, 'grounding'))
            || (str_contains($body, 'model') && (str_contains($body, 'does not exist') || str_contains($body, 'not found') || str_contains($body, 'invalid model') || str_contains($body, 'unknown model')));

        return match (true) {
            $status === 401, $status === 403 => $billing ? PauseReason::InsufficientCredits : PauseReason::InvalidKey,
            $status === 402 => PauseReason::InsufficientCredits,
            $status === 429 => $billing ? PauseReason::InsufficientCredits : PauseReason::RateLimited,
            $status === 404 => PauseReason::ModelUnavailable,
            $status === 400 && (str_contains($body, 'api key not valid') || str_contains($body, 'api_key_invalid') || str_contains($body, 'invalid api key')) => PauseReason::InvalidKey,
            $status === 400 && $billing => PauseReason::InsufficientCredits,
            $status === 400 && $badModel => PauseReason::ModelUnavailable,
            $status >= 500 => PauseReason::ProviderOutage,
            default => null,
        };
    }

    /**
     * The provider's own error code, when it has one, says more than the wording:
     * OpenAI-style `error.code`/`error.type`, Anthropic `error.type` and Google `error.status`.
     */
    protected static function classifyErrorFields(Response $response): ?PauseReason
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            return null;
        }

        $code = strtolower((string) ($error['code'] ?? ''));
        $type = strtolower((string) ($error['type'] ?? ''));
        $googleStatus = strtoupper((string) ($error['status'] ?? ''));
        $message = strtolower((string) ($error['message'] ?? ''));

        if ($googleStatus === 'RESOURCE_EXHAUSTED') {
            // Google words its per-minute limits as "exceeded your current quota…
            // plan and billing details", so only an explicit account problem means credits.
            $noCredits = preg_match('/\b(prepay\w*|prepaid|credits?|trial)\b[^.]*\b(depleted|exhausted|expired|used up|run out)\b/', $message)
                || preg_match('/billing (is |has )?not (been )?enabled|enable billing/', $message)
                // A quota of zero means the model needs a paid plan: waiting won't help.
                || preg_match('/\blimit: 0\b/', $message);

            return $noCredits ? PauseReason::InsufficientCredits : PauseReason::RateLimited;
        }

        return match (true) {
            $code === 'insufficient_quota', $type === 'insufficient_quota', $type === 'billing_error' => PauseReason::InsufficientCredits,
            $code === 'rate_limit_exceeded', $type === 'rate_limit_error' => PauseReason::RateLimited,
            $code === 'invalid_api_key', $type === 'authentication_error', $googleStatus === 'UNAUTHENTICATED' => PauseReason::InvalidKey,
            $code === 'model_not_found' => PauseReason::ModelUnavailable,
            default => null,
        };
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('error.0.message')
            ?? $response->json('message')
            ?? (is_string($response->json('error')) ? $response->json('error') : null)
            ?? 'HTTP ' . $response->status();

        return str($message)->limit(200)->toString();
    }

    protected function client(): PendingRequest
    {
        return Http::timeout(config('ai-visibility.http.timeout', 60))
            ->acceptJson()
            ->asJson();
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        return $this->parseCompletion($this->send(fn () => $this->sendCompletion($this->http($request->apiKey), $request)), $request);
    }

    /**
     * A plain request without web search.
     */
    abstract protected function sendCompletion(PendingRequest $http, CompletionRequest $request): Response;

    abstract protected function parseCompletion(Response $response, CompletionRequest $request): CompletionResponse;

    /**
     * The instruction to add when JSON output is required.
     */
    protected function jsonInstruction(CompletionRequest $request): string
    {
        return $request->json ? "\n\nRespond with valid JSON only, without code fences or comments." : '';
    }
}
