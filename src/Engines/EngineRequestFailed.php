<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\HttpEngine;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use RuntimeException;

/**
 * An engine call failed. `reason` says whether the whole engine should pause
 * (bad key, no credits…); null means only this request failed.
 */
class EngineRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?PauseReason $reason = null,
        public readonly ?int $retryAfter = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $message): self
    {
        $retryAfter = $response->header('retry-after');

        return new self(
            $message,
            HttpEngine::classifyFailure($response),
            is_numeric($retryAfter) ? (int) $retryAfter : self::googleRetryDelay($response),
            $response->status(),
        );
    }

    /**
     * Google sends the wait in the error details ("retryDelay": "36s") rather than a header.
     */
    protected static function googleRetryDelay(Response $response): ?int
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && preg_match('/^(\d+(?:\.\d+)?)s$/', (string) ($detail['retryDelay'] ?? ''), $match)) {
                return (int) ceil((float) $match[1]);
            }
        }

        return null;
    }

    /**
     * A failed item inside a batch, classified like a normal HTTP error.
     */
    public static function fromStatus(int $status, string $body, string $message): self
    {
        return self::fromResponse(new Response(new Psr7Response($status, [], $body)), $message);
    }

    public static function unreachable(string $message): self
    {
        return new self($message, PauseReason::ProviderOutage);
    }

    /**
     * Worth trying the same request again later.
     */
    public function isTransient(): bool
    {
        return in_array($this->reason, [PauseReason::RateLimited, PauseReason::ProviderOutage], true);
    }
}
