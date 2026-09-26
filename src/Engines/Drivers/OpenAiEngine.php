<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\BatchStatus;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\SupportsBatches;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\Concerns\ParsesResponsesApi;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;

class OpenAiEngine extends HttpEngine implements SupportsBatches
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
        return $http->post('/responses', $this->askBody($request));
    }

    /**
     * @return array<string, mixed>
     */
    protected function askBody(EngineRequest $request): array
    {
        $tool = ['type' => 'web_search'];

        if ($request->country) {
            $tool['user_location'] = ['type' => 'approximate', 'country' => $request->country];
        }

        return [
            'model' => $request->model,
            'input' => $request->prompt,
            'tools' => [$tool],
        ];
    }

    /**
     * Economy mode: upload the requests as a JSONL file and start a 24-hour batch.
     */
    public function submitBatch(string $apiKey, array $requests): string
    {
        $lines = [];

        foreach ($requests as $customId => $request) {
            $lines[] = json_encode([
                'custom_id' => (string) $customId,
                'method' => 'POST',
                'url' => '/v1/responses',
                'body' => $this->askBody($request),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // A multipart upload, so not the JSON client (its Content-Type header would stick).
        $file = $this->send(fn () => Http::timeout((int) config('ai-visibility.http.timeout', 60))
            ->acceptJson()
            ->baseUrl('https://api.openai.com/v1')
            ->withToken($apiKey)
            ->attach('file', implode("\n", $lines), 'ai-visibility-batch.jsonl')
            ->post('/files', ['purpose' => 'batch']));

        $batch = $this->send(fn () => $this->http($apiKey)->post('/batches', [
            'input_file_id' => $file->json('id'),
            'endpoint' => '/v1/responses',
            'completion_window' => '24h',
        ]));

        return (string) $batch->json('id');
    }

    public function batchStatus(string $apiKey, string $batchId): BatchStatus
    {
        $batch = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/batches/{$batchId}"));

        return match ($batch->json('status')) {
            // An expired batch still returns the answers it finished; the rest are retried.
            'completed', 'expired' => new BatchStatus(BatchStatus::DONE),
            'failed', 'cancelled', 'cancelling' => new BatchStatus(
                BatchStatus::FAILED,
                $batch->json('errors.data.0.message') ?? 'The batch ' . $batch->json('status') . '.',
            ),
            default => new BatchStatus(BatchStatus::PENDING),
        };
    }

    public function batchResults(string $apiKey, string $batchId): iterable
    {
        $batch = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/batches/{$batchId}"));

        foreach (array_filter([$batch->json('output_file_id'), $batch->json('error_file_id')]) as $fileId) {
            $content = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/files/{$fileId}/content"))->body();

            foreach (preg_split('/\r?\n/', trim($content)) as $line) {
                $item = json_decode($line, true);

                if (! is_array($item) || ! isset($item['custom_id'])) {
                    continue;
                }

                $status = (int) data_get($item, 'response.status_code', 0);
                $body = data_get($item, 'response.body');

                if ($status >= 200 && $status < 300 && is_array($body)) {
                    yield $item['custom_id'] => $this->answerFromPayload($body, (string) data_get($body, 'model', ''));

                    continue;
                }

                $message = data_get($body, 'error.message') ?? data_get($item, 'error.message') ?? 'The batch request failed.';

                yield $item['custom_id'] => $status > 0
                    ? EngineRequestFailed::fromStatus($status, (string) json_encode($body), $this->label() . ': ' . $message)
                    : new EngineRequestFailed($this->label() . ': ' . $message);
            }
        }
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
