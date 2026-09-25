<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

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
            is_numeric($retryAfter) ? (int) $retryAfter : null,
            $response->status(),
        );
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
