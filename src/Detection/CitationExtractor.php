<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Combines the engine's reported sources with links written in the answer
 * text (markdown links and bare URLs), without duplicates. Tracking
 * parameters are removed, and "http://www.acme.com/a/" and
 * "https://acme.com/a" count as the same page.
 */
class CitationExtractor
{
    /**
     * Query parameters that only track the click, removed from every URL.
     */
    protected const TRACKING_PARAMS = ['gclid', 'gclsrc', 'dclid', 'fbclid', 'msclkid', 'yclid', 'twclid', 'igshid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'srsltid', 'ref_src', 'ref_url'];

    /**
     * @return array<int, array{url: string, title: ?string, domain: string}>
     */
    public function extract(EngineResponse $response): array
    {
        $items = $response->citations;
        $answer = Text::clean($response->answer);

        // Allows one level of balanced parentheses, as in Wikipedia URLs.
        preg_match_all('/\[([^\]]{1,300})\]\((https?:\/\/(?:[^\s()]|\([^\s()]*\))+)\)/iu', $answer, $markdown, PREG_SET_ORDER);

        foreach ($markdown as [, $title, $url]) {
            $items[] = ['url' => $url, 'title' => $title];
        }

        // Bare URLs, skipping the targets of markdown links found above.
        preg_match_all('#(?<!\]\()(?<!\w)https?://[^\s<>"\'\]]+#iu', $answer, $bare);

        foreach ($bare[0] as $url) {
            $items[] = ['url' => static::trimTrailing($url), 'title' => null];
        }

        $citations = [];
        $seen = [];

        foreach (EngineResponse::uniqueCitations($items) as $citation) {
            $url = static::clean($citation['url']);
            $key = static::key($url);
            $domain = Domains::registrable($url);

            if (! $domain || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $citations[] = ['url' => $url, 'title' => $citation['title'], 'domain' => $domain];
        }

        return $citations;
    }

    /**
     * Remove punctuation and markdown that ended up after a URL ("…/pricing**",
     * "…/page)." when the bracket is not part of the URL).
     */
    public static function trimTrailing(string $url): string
    {
        do {
            $before = $url;
            $url = rtrim($url, '.,;:!?*_~`\'"');

            if (str_ends_with($url, ')') && substr_count($url, ')') > substr_count($url, '(')) {
                $url = substr($url, 0, -1);
            }
        } while ($url !== $before);

        return $url;
    }

    /**
     * The URL without tracking parameters or a fragment.
     */
    public static function clean(string $url): string
    {
        $url = static::trimTrailing(trim($url));
        $url = (string) preg_replace('/#.*$/s', '', $url);

        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return rtrim($url, '?');
        }

        $kept = array_filter(explode('&', $query), function (string $pair) {
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

            return $name !== '' && ! str_starts_with($name, 'utm_') && ! in_array($name, static::TRACKING_PARAMS, true);
        });

        $base = substr($url, 0, strpos($url, '?'));

        return $kept === [] ? $base : $base . '?' . implode('&', $kept);
    }

    /**
     * Comparison key: no scheme, no "www.", lowercase host, no trailing slash.
     */
    public static function key(string $url): string
    {
        $url = static::clean($url);
        $host = Domains::host($url) ?? '';
        $rest = (string) preg_replace('#^[a-z][a-z0-9+.-]*://[^/?]*#i', '', $url);
        $path = (string) parse_url('https://x' . $rest, PHP_URL_PATH);
        $query = parse_url('https://x' . $rest, PHP_URL_QUERY);

        return $host . rtrim($path, '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
