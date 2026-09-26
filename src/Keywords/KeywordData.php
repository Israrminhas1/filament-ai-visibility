<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords;

final class KeywordData
{
    public function __construct(
        public readonly string $keyword,
        public readonly ?int $searchVolume = null,
        public readonly ?int $clicks = null,
        public readonly ?int $impressions = null,
        public readonly ?float $position = null,
        public readonly ?string $intent = null,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {}
}
