<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use DOMDocument;
use DOMXPath;
use IsrarMinhas\FilamentAiVisibility\Competitors\EvidenceFetcher;
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

        // The shared fetcher refuses private addresses, checks redirects and caps the size.
        try {
            $html = app(EvidenceFetcher::class)->fetch($url);
        } catch (Throwable) {
            return null;
        }

        return $html === null ? null : $this->parse($html);
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
            // Site names carry taglines too: "Dr. John Mesa, MD | Plastic Surgery".
            'name' => $this->nameFromTitle($meta('//meta[@property="og:site_name"]')) ?? $this->nameFromTitle($title),
            'title' => $title,
            'description' => $meta('//meta[@name="description"]') ?? $meta('//meta[@property="og:description"]'),
            'language' => $htmlNode?->getAttribute('lang') ?: null,
        ];
    }

    /**
     * What a brand form can fill in from its website: a clean name (legal suffix
     * removed, the full legal name kept as another name) and a description.
     *
     * @return array{name: ?string, aliases: array<int, string>, description: ?string}|null
     */
    public function brandDetails(string $domain): ?array
    {
        $profile = $this->fetch($domain);

        if (! $profile) {
            return null;
        }

        $name = $profile['name'] ? static::withoutLegalSuffix($profile['name']) : null;

        return [
            'name' => $name,
            'aliases' => $name !== null && $name !== $profile['name'] ? [$profile['name']] : [],
            'description' => $profile['description'],
        ];
    }

    /**
     * "Nintendo Co., Ltd." → "Nintendo", "Acme GmbH" → "Acme", "Dr. Jane Roe, MD" →
     * "Dr. Jane Roe". Returns the name unchanged when nothing would be left.
     */
    public static function withoutLegalSuffix(string $name): string
    {
        $suffixes = 'co\.?,?\s*ltd|co\.?,?\s*limited|corporation|corp|incorporated|inc|llc|l\.l\.c|ltd|limited|plc|gmbh(?:\s*&\s*co\.?\s*kg)?|ag|kg|sa|s\.a|sas|sarl|s\.r\.l|srl|spa|s\.p\.a|bv|b\.v|nv|n\.v|oy|ab|as|a\/s|aps|pty\.?\s*ltd|pte\.?\s*ltd|kk|k\.k|co|lp|llp';
        // Professional letters only after a comma, so names like "Studio Do" stay whole.
        $credentials = 'md|m\.d|do|d\.o|dds|dmd|phd|ph\.d|esq|facs|cpa|pc|p\.c|pllc|pa|p\.a';
        $stripped = $name;

        // Repeat for names like "Acme Holdings Co., Ltd." ending in more than one suffix.
        do {
            $previous = $stripped;
            $stripped = rtrim((string) preg_replace('/[\s,]+(?:' . $suffixes . ')\.?$/iu', '', $stripped), ' ,.');
            $stripped = rtrim((string) preg_replace('/,\s*(?:' . $credentials . ')\.?$/iu', '', $stripped), ' ,.');
        } while ($stripped !== $previous && $stripped !== '');

        return $stripped !== '' ? $stripped : $name;
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
