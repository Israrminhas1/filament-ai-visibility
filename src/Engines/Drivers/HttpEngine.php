<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
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
        $body = strtolower($response->body());
        $status = $response->status();

        $billing = str_contains($body, 'insufficient_quota')
            || str_contains($body, 'billing')
            || str_contains($body, 'credit balance')
            || str_contains($body, 'credits')
            || str_contains($body, 'payment required')
            || str_contains($body, 'exceeded your current quota');

        return match (true) {
            $status === 401, $status === 403 => $billing ? PauseReason::InsufficientCredits : PauseReason::InvalidKey,
            $status === 402 => PauseReason::InsufficientCredits,
            $status === 429 => $billing ? PauseReason::InsufficientCredits : PauseReason::RateLimited,
            $status === 404 => PauseReason::ModelUnavailable,
            $status === 400 && (str_contains($body, 'api key not valid') || str_contains($body, 'api_key_invalid') || str_contains($body, 'invalid api key')) => PauseReason::InvalidKey,
            $status === 400 && $billing => PauseReason::InsufficientCredits,
            $status >= 500 => PauseReason::ProviderOutage,
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
}
