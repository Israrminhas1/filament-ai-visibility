<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\BatchStatus;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\SupportsBatches;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;

class AnthropicEngine extends HttpEngine implements SupportsBatches
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
     * Economy mode: the Message Batches API. A batch answer is a single turn,
     * so an answer that paused mid-search keeps what it had so far.
     */
    public function submitBatch(string $apiKey, array $requests): string
    {
        $items = [];

        foreach ($requests as $customId => $request) {
            $items[] = [
                'custom_id' => (string) $customId,
                'params' => $this->askParams($request, [['role' => 'user', 'content' => $request->prompt]]),
            ];
        }

        return (string) $this->send(fn () => $this->http($apiKey)->post('/messages/batches', ['requests' => $items]))->json('id');
    }

    public function batchStatus(string $apiKey, string $batchId): BatchStatus
    {
        $batch = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/messages/batches/{$batchId}"));

        return $batch->json('processing_status') === 'ended'
            ? new BatchStatus(BatchStatus::DONE)
            : new BatchStatus(BatchStatus::PENDING);
    }

    public function batchResults(string $apiKey, string $batchId): iterable
    {
        $content = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/messages/batches/{$batchId}/results"))->body();

        foreach (preg_split('/\r?\n/', trim($content)) as $line) {
            $item = json_decode($line, true);

            if (! is_array($item) || ! isset($item['custom_id'])) {
                continue;
            }

            $message = (array) data_get($item, 'result.message', []);

            yield $item['custom_id'] => match (data_get($item, 'result.type')) {
                'succeeded' => $this->answerFromBlocks(
                    (array) ($message['content'] ?? []),
                    (string) ($message['model'] ?? ''),
                    (int) data_get($message, 'usage.input_tokens', 0),
                    (int) data_get($message, 'usage.output_tokens', 0),
                    (int) data_get($message, 'usage.server_tool_use.web_search_requests', 0),
                ),
                'errored' => new EngineRequestFailed(
                    $this->label() . ': ' . (data_get($item, 'result.error.error.message') ?? 'The batch request failed.'),
                    self::batchErrorReason((string) data_get($item, 'result.error.error.type')),
                ),
                // Expired or canceled: it never ran, so it is retried in real time.
                default => new EngineRequestFailed(
                    $this->label() . ': the batch request ' . data_get($item, 'result.type', 'failed') . '.',
                    PauseReason::ProviderOutage,
                ),
            };
        }
    }

    protected static function batchErrorReason(string $type): ?PauseReason
    {
        return match ($type) {
            'authentication_error', 'permission_error' => PauseReason::InvalidKey,
            'billing_error' => PauseReason::InsufficientCredits,
            'rate_limit_error' => PauseReason::RateLimited,
            'overloaded_error', 'api_error' => PauseReason::ProviderOutage,
            'not_found_error' => PauseReason::ModelUnavailable,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function webSearchTool(EngineRequest $request): array
    {
        // The basic tool works on every model and in batches, and returns every
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
