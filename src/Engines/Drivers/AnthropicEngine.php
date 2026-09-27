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
            $response = $this->send(fn () => $http->post('/messages', $this->askParams($request, $messages)));

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
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function askParams(EngineRequest $request, array $messages): array
    {
        return [
            'model' => $request->model,
            'max_tokens' => (int) config('ai-visibility.tracking.max_output_tokens', 4096),
            'messages' => $messages,
            'tools' => [$this->webSearchTool($request)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function webSearchTool(EngineRequest $request): array
    {
        // The basic tool works on every model and returns every
        // search result directly. The newer versions filter results through
        // code execution, which some models reject and which hides sources.
        $tool = [
            'type' => 'web_search_20250305',
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
        $text = '';
        $cited = [];
        $results = [];
        $afterTool = false;

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $part = (string) ($block['text'] ?? '');

                // Citations split one paragraph into several text blocks, which join
                // as they are. Text after a search starts a new paragraph, so a
                // preamble ("I'll look into this.") doesn't run into the answer.
                if ($afterTool && trim($text) !== '' && trim($part) !== '') {
                    $text = rtrim($text) . "\n\n" . ltrim($part);
                } else {
                    $text .= $part;
                }

                $afterTool = $afterTool && trim($part) === '';
                array_push($cited, ...($block['citations'] ?? []));
            } else {
                $afterTool = true;

                if ($type === 'web_search_tool_result' && array_is_list($block['content'] ?? [])) {
                    // On errors `content` is an error object rather than a list of results.
                    array_push($results, ...$block['content']);
                }
            }
        }

        // Sources the answer actually cites come first, then everything the search returned.
        return new EngineResponse(
            answer: trim($text),
            citations: EngineResponse::uniqueCitations([...$cited, ...EngineResponse::readOnly($results)]),
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
