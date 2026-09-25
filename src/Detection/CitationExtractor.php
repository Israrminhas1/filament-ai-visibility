<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

/**
 * Combines the engine's reported sources with links written in the answer
 * text (markdown links and bare URLs), without duplicates.
 */
class CitationExtractor
{
    /**
     * @return array<int, array{url: string, title: ?string, domain: string}>
     */
    public function extract(EngineResponse $response): array
    {
        $items = $response->citations;

        preg_match_all('/\[([^\]]{1,300})\]\((https?:\/\/[^\s)]+)\)/i', $response->answer, $markdown, PREG_SET_ORDER);

        foreach ($markdown as [, $title, $url]) {
            $items[] = ['url' => $url, 'title' => $title];
        }

        preg_match_all('#(?<![(\w])https?://[^\s)\]>"\']+#i', $response->answer, $bare);

        foreach ($bare[0] as $url) {
            $items[] = ['url' => rtrim($url, '.,;:!?'), 'title' => null];
        }

        $citations = [];

        foreach (EngineResponse::uniqueCitations($items) as $citation) {
            $domain = Domains::registrable($citation['url']);

            if ($domain) {
                $citations[] = $citation + ['domain' => $domain];
            }
        }

        return $citations;
    }
}
