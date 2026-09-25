<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads public basics from a website (title, description, site name) to
 * pre-fill a brand. No AI involved.
 */
class WebsiteProfile
{
    /**
     * @return array{name: ?string, title: ?string, description: ?string, language: ?string}|null
     */
    public function fetch(string $domain): ?array
    {
        $url = str_starts_with($domain, 'http') ? $domain : 'https://' . $domain;

        try {
            $response = Http::timeout(config('ai-visibility.http.website_fetch_timeout', 10))
                ->withUserAgent(config('ai-visibility.http.user_agent'))
                ->accept('text/html')
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! str_contains((string) $response->header('Content-Type'), 'html')) {
            return null;
        }

        return $this->parse($response->body());
    }

    /**
     * @return array{name: ?string, title: ?string, description: ?string, language: ?string}
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 500_000));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $meta = function (string $query) use ($xpath): ?string {
            $node = $xpath->query($query)->item(0);

            return $node ? Text::squish($node->getAttribute('content')) ?: null : null;
        };

        $titleNode = $xpath->query('//title')->item(0);
        $title = $titleNode ? Text::squish($titleNode->textContent) ?: null : null;
        $htmlNode = $xpath->query('//html')->item(0);

        return [
            'name' => $meta('//meta[@property="og:site_name"]') ?? $this->nameFromTitle($title),
            'title' => $title,
            'description' => $meta('//meta[@name="description"]') ?? $meta('//meta[@property="og:description"]'),
            'language' => $htmlNode?->getAttribute('lang') ?: null,
        ];
    }

    /**
     * "Acme CRM | Simple CRM for agencies" → "Acme CRM"
     */
    protected function nameFromTitle(?string $title): ?string
    {
        if (! $title) {
            return null;
        }

        $parts = preg_split('/\s+[|\-–—:·]\s+/u', $title);

        return Text::squish($parts[0] ?? $title) ?: null;
    }
}
