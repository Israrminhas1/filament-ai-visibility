<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

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

    /**
     * Grounded with Google Search.
     */
    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        return $http->post('/models/' . rawurlencode($request->model) . ':generateContent', [
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'tools' => [['google_search' => (object) []]],
        ]);
    }

    protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse
    {
        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode('');

        // Grounding URLs are Google redirect links; the title holds the source's domain.
        $citations = collect($response->json('candidates.0.groundingMetadata.groundingChunks', []))
            ->map(fn (array $chunk) => $chunk['web'] ?? null)
            ->filter()
            ->map(fn (array $web) => [
                'url' => static::isRedirect($web['uri'] ?? '') && filled($web['title'] ?? null) && str_contains($web['title'], '.')
                    ? 'https://' . $web['title'] . '/'
                    : ($web['uri'] ?? null),
                'title' => $web['title'] ?? null,
            ]);

        $queries = $response->json('candidates.0.groundingMetadata.webSearchQueries', []);

        return new EngineResponse(
            answer: trim($text),
            citations: EngineResponse::uniqueCitations($citations),
            model: $response->json('modelVersion') ?? $request->model,
            inputTokens: $response->json('usageMetadata.promptTokenCount'),
            outputTokens: $response->json('usageMetadata.candidatesTokenCount'),
            searches: $queries ? 1 : 0,
        );
    }

    protected static function isRedirect(string $url): bool
    {
        return str_contains($url, 'vertexaisearch.cloud.google.com') || str_contains($url, 'grounding-api-redirect');
    }
}
