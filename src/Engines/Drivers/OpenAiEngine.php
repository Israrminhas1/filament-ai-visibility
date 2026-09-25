<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\Concerns\ParsesResponsesApi;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;

class OpenAiEngine extends HttpEngine
{
    use ParsesResponsesApi;

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

    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        $tool = ['type' => 'web_search'];

        if ($request->country) {
            $tool['user_location'] = ['type' => 'approximate', 'country' => $request->country];
        }

        return $http->post('/responses', [
            'model' => $request->model,
            'input' => $request->prompt,
            'tools' => [$tool],
        ]);
    }

    protected function sendCompletion(PendingRequest $http, CompletionRequest $request): Response
    {
        $body = [
            'model' => $request->model,
            'input' => $request->prompt . $this->jsonInstruction($request),
            'max_output_tokens' => $request->maxTokens,
        ];

        if ($request->system) {
            $body['instructions'] = $request->system;
        }

        return $http->post('/responses', $body);
    }

    protected function parseCompletion(Response $response, CompletionRequest $request): CompletionResponse
    {
        $answer = $this->parseAnswer($response, new EngineRequest($request->prompt, $request->model, $request->apiKey));

        return new CompletionResponse($answer->answer, $answer->model, $answer->inputTokens, $answer->outputTokens);
    }
}
