<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class OpenAiEngine extends HttpEngine
{
    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI (ChatGPT)';
    }

    protected function http(string $apiKey): PendingRequest
    {
        return $this->client()->baseUrl('https://api.openai.com/v1')->withToken($apiKey);
    }

    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->get('/models');
    }
}
