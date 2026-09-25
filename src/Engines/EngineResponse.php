<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

final class EngineResponse
{
    /**
     * @param  array<int, array{url: string, title: ?string}>  $citations  Sources in the order the engine gave them.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $citations,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $searches = 0,
    ) {}

    /**
     * @param  iterable<mixed>  $items
     * @return array<int, array{url: string, title: ?string}>
     */
    public static function uniqueCitations(iterable $items): array
    {
        $seen = [];
        $citations = [];

        foreach ($items as $item) {
            $url = is_array($item) ? ($item['url'] ?? $item['uri'] ?? null) : $item;

            if (! is_string($url) || ! preg_match('#^https?://#i', $url) || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $citations[] = ['url' => $url, 'title' => is_array($item) ? ($item['title'] ?? null) : null];
        }

        return $citations;
    }
}
