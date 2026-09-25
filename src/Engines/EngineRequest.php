<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

final class EngineRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $model,
        public readonly string $apiKey,
        /** Two-letter country code for engines that localise search results. */
        public readonly ?string $country = null,
    ) {}
}
