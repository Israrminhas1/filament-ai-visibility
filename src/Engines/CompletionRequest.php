<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

/**
 * A plain helper request (no web search), e.g. for classification or extraction.
 */
final class CompletionRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $model,
        public readonly string $apiKey,
        public readonly bool $json = true,
        public readonly int $maxTokens = 4096,
        public readonly ?string $system = null,
    ) {}
}
