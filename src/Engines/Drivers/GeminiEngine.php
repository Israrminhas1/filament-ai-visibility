<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class GeminiEngine extends HttpEngine
{
    public function key(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    public function aiMonitorProviders(): array
    {
        return ['gemini', 'google'];
    }

    protected function http(string $apiKey): PendingRequest
    {
        // Sent as a header rather than a query parameter so the key never appears in URLs or logs.
        return $this->client()
            ->baseUrl('https://generativelanguage.googleapis.com/v1beta')
            ->withHeaders(['x-goog-api-key' => $apiKey]);
    }

    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->get('/models', ['pageSize' => 200]);
    }

    protected function modelsFromKeyTest(Response $response): array
    {
        return collect($response->json('models', []))
            ->pluck('name')
            ->map(fn (string $name) => str_replace('models/', '', $name))
            ->filter(fn (string $name) => str_starts_with($name, 'gemini'))
            ->sort()
            ->values()
            ->all();
    }
}
