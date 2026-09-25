<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class GrokEngine extends HttpEngine
{
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
}
