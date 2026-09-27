<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

final class EngineResponse
{
    /**
     * @param  array<int, array{url: string, title: ?string, read?: true}>  $citations  Sources in the order the engine gave them; `read` marks sources only read, not cited.
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
     * Mark sources the engine only read while searching, as opposed to ones
     * it cited in the answer. List cited sources first: the first copy of a URL wins.
     *
     * @param  iterable<mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function readOnly(iterable $items): array
    {
        $read = [];

        foreach ($items as $item) {
            $read[] = is_array($item) ? [...$item, 'read' => true] : ['url' => $item, 'read' => true];
        }

        return $read;
    }

    /**
     * Keeps the first copy of each URL. A source that is both read and cited counts as cited.
     *
     * @param  iterable<mixed>  $items
     * @return array<int, array{url: string, title: ?string, read?: true}>
     */
    public static function uniqueCitations(iterable $items): array
    {
        $seen = [];
        $citations = [];

        foreach ($items as $item) {
            $url = is_array($item) ? ($item['url'] ?? $item['uri'] ?? null) : $item;
            $read = is_array($item) && ! empty($item['read']);

            if (! is_string($url) || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            if (isset($seen[$url])) {
                if (! $read) {
                    unset($citations[$seen[$url]]['read']);
                }

                continue;
            }

            $seen[$url] = count($citations);
            $citations[] = ['url' => $url, 'title' => is_array($item) ? ($item['title'] ?? null) : null] + ($read ? ['read' => true] : []);
        }

        return $citations;
    }
}
