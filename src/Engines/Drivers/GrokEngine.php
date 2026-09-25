<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\Concerns\ParsesResponsesApi;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;

class GrokEngine extends HttpEngine
{
    use ParsesResponsesApi;

    public function key(): string
    {
        return 'grok';
    }

    public function label(): string
    {
        return 'xAI (Grok)';
    }

    public function aiMonitorProviders(): array
    {
        return ['grok', 'xai'];
    }

    protected function http(string $apiKey): PendingRequest
    {
        return $this->client()->baseUrl('https://api.x.ai/v1')->withToken($apiKey);
    }

    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->get('/models');
    }

    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        return $http->post('/responses', [
            'model' => $request->model,
            'input' => [['role' => 'user', 'content' => $request->prompt]],
            'tools' => [['type' => 'web_search']],
        ]);
    }
}
