<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
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

        $queries = (array) $response->json('candidates.0.groundingMetadata.webSearchQueries', []);
        $model = $response->json('modelVersion') ?? $request->model;

        return new EngineResponse(
            answer: trim($text),
            citations: EngineResponse::uniqueCitations($citations),
            model: $model,
            inputTokens: $response->json('usageMetadata.promptTokenCount'),
            outputTokens: $response->json('usageMetadata.candidatesTokenCount'),
            // Gemini 3 bills every search query; older models bill once per grounded prompt.
            searches: str_starts_with($model, 'gemini-2') ? ($queries ? 1 : 0) : count($queries),
        );
    }

    protected static function isRedirect(string $url): bool
    {
        return str_contains($url, 'vertexaisearch.cloud.google.com') || str_contains($url, 'grounding-api-redirect');
    }

    protected function sendCompletion(PendingRequest $http, CompletionRequest $request): Response
    {
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'generationConfig' => array_filter([
                'maxOutputTokens' => $request->maxTokens,
                'responseMimeType' => $request->json ? 'application/json' : null,
            ]),
        ];

        if ($request->system) {
            $body['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }

        return $http->post('/models/' . rawurlencode($request->model) . ':generateContent', $body);
    }

    protected function parseCompletion(Response $response, CompletionRequest $request): CompletionResponse
    {
        return new CompletionResponse(
            text: collect($response->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode(''),
            model: $response->json('modelVersion') ?? $request->model,
            inputTokens: $response->json('usageMetadata.promptTokenCount'),
            outputTokens: $response->json('usageMetadata.candidatesTokenCount'),
        );
    }
}
