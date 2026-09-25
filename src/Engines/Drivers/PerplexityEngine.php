<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

class PerplexityEngine extends HttpEngine
{
    public function key(): string
    {
        return 'perplexity';
    }

    public function label(): string
    {
        return 'Perplexity';
    }

    protected function http(string $apiKey): PendingRequest
    {
        return $this->client()->baseUrl('https://api.perplexity.ai')->withToken($apiKey);
    }

    /**
     * Perplexity has no model-listing endpoint, so the test is a one-token
     * completion (costs a fraction of a cent).
     */
    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->post('/chat/completions', [
            'model' => $this->defaultHelperModel(),
            'max_tokens' => 1,
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);
    }

    protected function modelsFromKeyTest(Response $response): array
    {
        return [];
    }

    /**
     * Sonar models always search the web.
     */
    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        $body = [
            'model' => $request->model,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];

        if ($request->country) {
            $body['web_search_options'] = ['user_location' => ['country' => $request->country]];
        }

        return $http->post('/chat/completions', $body);
    }

    protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse
    {
        $citations = $response->json('search_results') ?: $response->json('citations', []);

        return new EngineResponse(
            answer: trim((string) $response->json('choices.0.message.content')),
            citations: EngineResponse::uniqueCitations($citations),
            model: $response->json('model') ?? $request->model,
            inputTokens: $response->json('usage.prompt_tokens'),
            outputTokens: $response->json('usage.completion_tokens'),
            searches: 1,
        );
    }
}
