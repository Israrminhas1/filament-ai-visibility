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
use Throwable;

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
            $response = Http::timeout(20)->retry(...HttpEngine::connectRetry())->get('https://serpapi.com/account.json', ['api_key' => $apiKey]);
        } catch (ConnectionException) {
            return KeyTestResult::failed(PauseReason::ProviderOutage, 'could not reach SerpAPI');
        } catch (Throwable $e) {
            return KeyTestResult::failed(PauseReason::ProviderOutage, static::redact($e->getMessage(), $apiKey));
        }

        if ($response->failed() || $response->json('error')) {
            return KeyTestResult::failed(static::reason($response) ?? PauseReason::InvalidKey, static::redact((string) ($response->json('error') ?: 'HTTP ' . $response->status()), $apiKey));
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
                ->retry(...HttpEngine::connectRetry())
                ->get('https://serpapi.com/search.json', array_filter([...$params, 'api_key' => $apiKey], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            // The key is a query parameter, so connection errors quote it in the URL.
            throw EngineRequestFailed::unreachable('Could not reach SerpAPI: ' . static::redact($e->getMessage(), $apiKey));
        } catch (EngineRequestFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EngineRequestFailed($this->label() . ': ' . static::redact($e->getMessage(), $apiKey));
        }

        $error = (string) $response->json('error');

        // "No results" is a normal outcome, not a failure.
        if ($response->successful() && ($error === '' || str_contains(strtolower($error), 'hasn\'t returned any results'))) {
            return $response;
        }

        throw new EngineRequestFailed(static::redact($this->label() . ': ' . ($error ?: 'HTTP ' . $response->status()), $apiKey), static::reason($response), status: $response->status());
    }

    /**
     * Remove the API key from text that is stored or shown.
     */
    public static function redact(string $message, string $apiKey): string
    {
        if ($apiKey !== '') {
            $message = str_replace([$apiKey, rawurlencode($apiKey), urlencode($apiKey)], '[redacted]', $message);
        }

        return preg_replace('/(api_key=)[^&\s"\']+/i', '$1[redacted]', $message) ?? $message;
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
            fn ($ref) => [
                'url' => static::sourceUrl($ref),
                'title' => static::clean((string) ($ref['title'] ?? ($ref['source'] ?? ''))) ?: null,
            ],
            $references,
        ));
    }

    /**
     * The real address of a reference. Google sometimes gives an opaque
     * "google.com/goto?url=…" link; the site is then read from the favicon URL.
     *
     * @param  array<string, mixed>  $ref
     */
    public static function sourceUrl(array $ref): ?string
    {
        $link = (string) ($ref['link'] ?? '');

        if ($link !== '' && ! preg_match('#^https?://(www\.)?google\.[a-z.]+/(goto|url)\b#i', $link)) {
            return $link;
        }

        parse_str((string) parse_url((string) ($ref['source_icon'] ?? ''), PHP_URL_QUERY), $query);
        $site = (string) ($query['url'] ?? '');

        return preg_match('#^https?://[^/]+#i', $site, $match) ? $match[0] . '/' : null;
    }

    /**
     * SerpAPI text can carry literal "&" sequences and needless markdown
     * escapes ("built\-in"); turn them back into plain characters.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $text);

        // Only escapes that can't change the markdown when removed.
        return (string) preg_replace('/\\\\([\-().!#+\'"&])/', '$1', $text);
    }

    protected function answer(string $text, array $references, EngineRequest $request, int $searches): EngineResponse
    {
        $text = static::clean($text);

        return new EngineResponse(
            answer: $text !== '' ? $text : static::NO_ANSWER,
            citations: $this->citations($references),
            model: $this->key(),
            searches: $searches,
        );
    }
}
