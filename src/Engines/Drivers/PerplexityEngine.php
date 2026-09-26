<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\Concerns\ParsesResponsesApi;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

/**
 * Perplexity's Agent API (POST /v1/agent), which replaced Sonar Chat
 * Completions. Answers come back like OpenAI's Responses API, plus a
 * `search_results` item listing every source that was read.
 */
class PerplexityEngine extends HttpEngine
{
    use ParsesResponsesApi {
        parseAnswer as protected parseResponsesAnswer;
    }

    /**
     * Old Sonar model names and the Agent API presets Perplexity maps them to.
     */
    protected const LEGACY_PRESETS = [
        'sonar-pro' => 'low',
        'sonar-reasoning-pro' => 'medium',
        'sonar-deep-research' => 'high',
    ];

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
        return $this->client()->baseUrl('https://api.perplexity.ai/v1')->withToken($apiKey);
    }

    /**
     * Perplexity has no model-listing endpoint, so the test is a tiny request
     * without web search (costs a fraction of a cent).
     */
    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->post('/agent', [
            'model' => $this->defaultHelperModel(),
            'input' => 'Hi',
            'max_output_tokens' => 16,
        ]);
    }

    protected function modelsFromKeyTest(Response $response): array
    {
        return [];
    }

    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        $body = [
            ...$this->modelFields($request->model),
            'input' => $request->prompt,
            'max_output_tokens' => (int) config('ai-visibility.tracking.max_output_tokens', 4096),
        ];

        // Presets bring their own tools.
        if (! isset($body['preset'])) {
            $tool = ['type' => 'web_search'];

            if ($request->country) {
                $tool['user_location'] = ['country' => $request->country];
            }

            $body['tools'] = [$tool];
        }

        return $http->post('/agent', $body);
    }

    protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse
    {
        $answer = $this->parseResponsesAnswer($response, $request);
        $results = [];
        $searchItems = 0;

        foreach ((array) $response->json('output', []) as $item) {
            if (($item['type'] ?? null) === 'search_results') {
                $searchItems++;
                array_push($results, ...(array) ($item['results'] ?? []));
            }
        }

        // Sources the answer cites come first, then everything the search returned.
        return new EngineResponse(
            answer: $answer->answer ?: trim((string) $response->json('output_text')),
            citations: EngineResponse::uniqueCitations([...$answer->citations, ...$results]),
            model: $answer->model,
            inputTokens: $answer->inputTokens,
            outputTokens: $answer->outputTokens,
            searches: (int) ($response->json('usage.tool_calls_details.web_search.invocation') ?? $searchItems),
        );
    }

    protected function sendCompletion(PendingRequest $http, CompletionRequest $request): Response
    {
        return $http->post('/agent', array_filter([
            ...$this->modelFields($request->model),
            'input' => $request->prompt . $this->jsonInstruction($request),
            'instructions' => $request->system,
            'max_output_tokens' => $request->maxTokens,
        ]));
    }

    protected function parseCompletion(Response $response, CompletionRequest $request): CompletionResponse
    {
        $answer = $this->parseResponsesAnswer($response, new EngineRequest($request->prompt, $request->model, $request->apiKey));

        return new CompletionResponse(
            $answer->answer ?: (string) $response->json('output_text'),
            $answer->model,
            $answer->inputTokens,
            $answer->outputTokens,
        );
    }

    /**
     * "provider/model" names go in `model`; old Sonar names still work.
     *
     * @return array{model?: string, preset?: string}
     */
    protected function modelFields(string $model): array
    {
        $model = trim($model);

        if (isset(self::LEGACY_PRESETS[$model])) {
            return ['preset' => self::LEGACY_PRESETS[$model]];
        }

        return ['model' => str_contains($model, '/') ? $model : 'perplexity/' . $model];
    }
}
