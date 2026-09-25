<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class AnthropicEngine extends HttpEngine
{
    public function key(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic (Claude)';
    }

    public function aiMonitorProviders(): array
    {
        return ['anthropic', 'claude'];
    }

    protected function http(string $apiKey): PendingRequest
    {
        return $this->client()
            ->baseUrl('https://api.anthropic.com/v1')
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ]);
    }

    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->get('/models', ['limit' => 100]);
    }
}
