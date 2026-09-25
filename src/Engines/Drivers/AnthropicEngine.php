<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

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

    /**
     * Claude with the web search server tool. A long search can end with
     * `pause_turn`; the conversation is then continued (a few times at most).
     */
    public function ask(EngineRequest $request): EngineResponse
    {
        $http = $this->http($request->apiKey);
        $messages = [['role' => 'user', 'content' => $request->prompt]];
        $blocks = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $searches = 0;
        $model = $request->model;

        for ($turn = 0; $turn < 3; $turn++) {
            $response = $this->send(fn () => $http->post('/messages', [
                'model' => $request->model,
                'max_tokens' => (int) config('ai-visibility.tracking.max_output_tokens', 4096),
                'messages' => $messages,
                'tools' => [$this->webSearchTool($request)],
            ]));

            $content = $response->json('content', []);
            $blocks = [...$blocks, ...$content];
            $inputTokens += (int) $response->json('usage.input_tokens', 0);
            $outputTokens += (int) $response->json('usage.output_tokens', 0);
            $searches += (int) $response->json('usage.server_tool_use.web_search_requests', 0);
            $model = $response->json('model', $model);

            if ($response->json('stop_reason') !== 'pause_turn') {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
        }

        return $this->answerFromBlocks($blocks, $model, $inputTokens, $outputTokens, $searches);
    }

    /**
     * @return array<string, mixed>
     */
    protected function webSearchTool(EngineRequest $request): array
    {
        // Newer models support the dynamic-filtering version of the tool; older ones only the basic one.
        $basicOnly = (bool) preg_match('/haiku|claude-3|claude-(opus|sonnet)-4-[0-5]\b|claude-(opus|sonnet)-4$/', $request->model);

        $tool = [
            'type' => $basicOnly ? 'web_search_20250305' : 'web_search_20260209',
            'name' => 'web_search',
            'max_uses' => (int) config('ai-visibility.tracking.max_searches', 5),
        ];

        if ($request->country) {
            $tool['user_location'] = ['type' => 'approximate', 'country' => $request->country];
        }

        return $tool;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    protected function answerFromBlocks(array $blocks, string $model, int $inputTokens, int $outputTokens, int $searches): EngineResponse
    {
        $text = [];
        $cited = [];
        $results = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $text[] = $block['text'] ?? '';
                array_push($cited, ...($block['citations'] ?? []));
            } elseif ($type === 'web_search_tool_result' && array_is_list($block['content'] ?? [])) {
                // On errors `content` is an error object rather than a list of results.
                array_push($results, ...$block['content']);
            }
        }

        // Sources the answer actually cites come first, then everything the search returned.
        return new EngineResponse(
            answer: trim(implode('', $text)),
            citations: EngineResponse::uniqueCitations([...$cited, ...$results]),
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            searches: $searches,
        );
    }

    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        throw new \LogicException('AnthropicEngine overrides ask().');
    }

    protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse
    {
        throw new \LogicException('AnthropicEngine overrides ask().');
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $response = $this->send(fn () => $this->http($request->apiKey)->post('/messages', array_filter([
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => [['role' => 'user', 'content' => $request->prompt . $this->jsonInstruction($request)]],
        ])));

        return new CompletionResponse(
            text: collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode(''),
            model: $response->json('model') ?? $request->model,
            inputTokens: $response->json('usage.input_tokens'),
            outputTokens: $response->json('usage.output_tokens'),
        );
    }

    protected function sendCompletion(PendingRequest $http, CompletionRequest $request): Response
    {
        throw new \LogicException('AnthropicEngine overrides complete().');
    }

    protected function parseCompletion(Response $response, CompletionRequest $request): CompletionResponse
    {
        throw new \LogicException('AnthropicEngine overrides complete().');
    }
}
