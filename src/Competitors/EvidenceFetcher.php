<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Models\DomainProfile;
use IsrarMinhas\FilamentAiVisibility\Support\Text;
use Throwable;

/**
 * Reads what a website says about itself (title, description, headings and
 * opening text of the home and about pages), so classification is based on
 * evidence rather than a guess from the domain name.
 */
class EvidenceFetcher
{
    public function profile(string $domain): DomainProfile
    {
        $profile = DomainProfile::query()->firstOrNew(['domain' => strtolower($domain)]);

        if ($profile->exists && $profile->isFresh()) {
            return $profile;
        }

        $home = $this->page("https://{$domain}/");
        $about = ($home === null || mb_strlen($home['excerpt']) < 400) ? $this->page("https://{$domain}/about") : null;

        if ($home === null && $about === null) {
            $profile->fill(['status' => 'unreachable', 'fetched_at' => now()])->save();

            return $profile;
        }

        $page = $home ?? $about;

        $profile->fill([
            'title' => $page['title'],
            'description' => $page['description'],
            'headings' => array_slice(array_values(array_unique([...($home['headings'] ?? []), ...($about['headings'] ?? [])])), 0, 15),
            'excerpt' => mb_substr(trim(($home['excerpt'] ?? '') . ' ' . ($about['excerpt'] ?? '')), 0, 2000),
            'status' => 'ok',
            'fetched_at' => now(),
        ])->save();

        return $profile;
    }

    /**
     * @return array{title: ?string, description: ?string, headings: array<string>, excerpt: string}|null
     */
    protected function page(string $url): ?array
    {
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
     * @return array{title: ?string, description: ?string, headings: array<string>, excerpt: string}
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . mb_substr($html, 0, 800_000));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//header//nav|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $meta = fn (string $query) => ($node = $xpath->query($query)->item(0)) ? (Text::squish($node->getAttribute('content')) ?: null) : null;

        $headings = [];

        foreach ($xpath->query('//h1|//h2|//h3') as $node) {
            $text = Text::squish($node->textContent);

            if ($text !== '' && mb_strlen($text) < 150) {
                $headings[] = $text;
            }

            if (count($headings) >= 12) {
                break;
            }
        }

        $body = $xpath->query('//body')->item(0);
        $title = $xpath->query('//title')->item(0);

        return [
            'title' => $title ? (Text::squish($title->textContent) ?: null) : null,
            'description' => $meta('//meta[@name="description"]') ?? $meta('//meta[@property="og:description"]'),
            'headings' => $headings,
            'excerpt' => mb_substr(Text::squish($body?->textContent ?? ''), 0, 1500),
        ];
    }
}
