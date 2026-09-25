<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

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
}
