<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyTestResult;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;

/**
 * Google's own AI answers, read through SerpAPI. Both Google engines share
 * one SerpAPI key. When Google shows no AI answer for a search, that is
 * recorded as an answer that does not mention anyone.
 */
abstract class SerpApiGoogleEngine implements Engine
{
    public const NO_ANSWER = 'Google did not show an AI answer for this search.';

    public function credentialKey(): string
    {
        return 'serpapi';
    }

    public function aiMonitorProviders(): array
    {
        return ['serpapi'];
    }

    public function supportsCompletion(): bool
    {
        return false;
    }

    public function defaultTrackingModel(): string
    {
        return $this->key();
    }

    public function defaultHelperModel(): string
    {
        return $this->key();
    }

    public function suggestedModels(): array
    {
        return [$this->key()];
    }

    public function testKey(string $apiKey): KeyTestResult
    {
        try {
            $response = Http::timeout(20)->get('https://serpapi.com/account.json', ['api_key' => $apiKey]);
        } catch (ConnectionException) {
            return KeyTestResult::failed(PauseReason::ProviderOutage, 'could not reach SerpAPI');
        }

        if ($response->failed() || $response->json('error')) {
            return KeyTestResult::failed(static::reason($response) ?? PauseReason::InvalidKey, $response->json('error'));
        }

        if ((int) ($response->json('total_searches_left') ?? 1) <= 0) {
            return KeyTestResult::failed(PauseReason::InsufficientCredits, 'no SerpAPI searches left this month');
        }

        return KeyTestResult::ok('Connected (' . ($response->json('total_searches_left') ?? '?') . ' searches left)');
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        throw new EngineRequestFailed($this->label() . ' cannot be used for helper features.');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function search(string $apiKey, array $params): Response
    {
        try {
            $response = Http::timeout((int) config('ai-visibility.http.timeout', 60))
                ->get('https://serpapi.com/search.json', array_filter([...$params, 'api_key' => $apiKey], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            throw EngineRequestFailed::unreachable('Could not reach SerpAPI: ' . $e->getMessage());
        }

        $error = (string) $response->json('error');

        // "No results" is a normal outcome, not a failure.
        if ($response->successful() && ($error === '' || str_contains(strtolower($error), 'hasn\'t returned any results'))) {
            return $response;
        }

        throw new EngineRequestFailed($this->label() . ': ' . ($error ?: 'HTTP ' . $response->status()), static::reason($response), status: $response->status());
    }

    public static function reason(Response $response): ?PauseReason
    {
        $error = strtolower((string) $response->json('error'));

        return match (true) {
            $response->status() === 401, str_contains($error, 'invalid api key') => PauseReason::InvalidKey,
            str_contains($error, 'run out of searches'), str_contains($error, 'plan'), $response->status() === 402 => PauseReason::InsufficientCredits,
            $response->status() === 429 => PauseReason::RateLimited,
            $response->status() >= 500 => PauseReason::ProviderOutage,
            default => null,
        };
    }

    /**
     * Turn SerpAPI text blocks into readable text (paragraphs, headings and lists).
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    protected function blocksToText(array $blocks, int $depth = 0): string
    {
        $lines = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? 'paragraph';
            $snippet = trim((string) ($block['snippet'] ?? ''));

            if ($type === 'heading' && $snippet !== '') {
                $lines[] = '## ' . $snippet;
            } elseif ($type === 'list') {
                foreach ((array) ($block['list'] ?? []) as $item) {
                    $text = trim(implode(': ', array_filter([$item['title'] ?? null, $item['snippet'] ?? null])));
                    $lines[] = str_repeat('  ', $depth) . '- ' . $text;

                    if (! empty($item['list'])) {
                        $lines[] = $this->blocksToText([['type' => 'list', 'list' => $item['list']]], $depth + 1);
                    }
                }
            } elseif ($type === 'expandable') {
                $lines[] = trim(($block['title'] ?? '') . "\n" . $this->blocksToText((array) ($block['text_blocks'] ?? []), $depth));
            } elseif ($snippet !== '') {
                $lines[] = $snippet;
            }
        }

        return trim(implode("\n", array_filter($lines, fn ($line) => trim($line) !== '')));
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array{url: string, title: ?string}>
     */
    protected function citations(array $references): array
    {
        return EngineResponse::uniqueCitations(array_map(
            fn ($ref) => ['url' => $ref['link'] ?? null, 'title' => $ref['title'] ?? ($ref['source'] ?? null)],
            $references,
        ));
    }

    protected function answer(string $text, array $references, EngineRequest $request, int $searches): EngineResponse
    {
        return new EngineResponse(
            answer: $text !== '' ? $text : static::NO_ANSWER,
            citations: $this->citations($references),
            model: $this->key(),
            searches: $searches,
        );
    }
}
