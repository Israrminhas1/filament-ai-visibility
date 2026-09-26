<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Keeps obvious non-competitors out of discovery: the brand itself and its
 * own products, and big platforms (social networks, review sites, forums,
 * publishers) that answers cite but nobody competes with.
 */
class NoiseFilter
{
    /**
     * Never competitors, whatever the brand does.
     */
    public const PLATFORM_DOMAINS = [
        'reddit.com', 'youtube.com', 'wikipedia.org', 'g2.com', 'capterra.com', 'trustpilot.com',
        'forbes.com', 'medium.com', 'quora.com', 'linkedin.com', 'facebook.com', 'x.com',
        'twitter.com', 'instagram.com', 'tiktok.com', 'amazon.com', 'github.com',
    ];

    /**
     * Source categories (config('ai-visibility.source_categories')) that are
     * not competitors, so their domains do not become candidates on their own.
     */
    public const NON_COMPETITOR_CATEGORIES = [
        'review_comparison', 'forum_community', 'media_publisher', 'social', 'wiki_reference', 'government_education',
    ];

    /**
     * Legal endings removed before comparing names ("Nintendo Co., Ltd." is "Nintendo").
     */
    public const LEGAL_SUFFIXES = [
        'co', 'ltd', 'inc', 'llc', 'gmbh', 'corp', 'corporation', 'plc', 'ag', 'sa', 's a', 'limited', 'company', 'incorporated',
    ];

    /**
     * Normalised name without legal endings: "Nintendo Co., Ltd." → "nintendo".
     */
    public static function baseName(?string $name): string
    {
        $name = Text::normalize($name);
        $pattern = '/(?:\s+(?:' . implode('|', array_map(fn ($s) => preg_quote($s, '/'), self::LEGAL_SUFFIXES)) . '))+$/u';
        $stripped = trim((string) preg_replace($pattern, '', $name));

        return $stripped !== '' ? $stripped : $name;
    }

    /**
     * The brand's names (name and aliases), normalised and without legal endings.
     *
     * @return array<string>
     */
    public static function ownNames(Brand $brand): array
    {
        return collect($brand->names())
            ->map(fn ($name) => static::baseName($name))
            ->filter(fn ($name) => mb_strlen($name) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a name is the brand or one of its products: it contains the
     * brand name or an alias as a whole word ("Nintendo Switch 2").
     */
    public static function isOwnName(Brand $brand, string $name): bool
    {
        $name = static::baseName($name);

        if ($name === '') {
            return false;
        }

        foreach (static::ownNames($brand) as $own) {
            if ($name === $own || preg_match('/(?<![\p{L}\p{N}])' . preg_quote($own, '/') . '(?![\p{L}\p{N}])/u', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a domain belongs to the brand: one of its domains (or a
     * subdomain), or the brand name on another ending ("nintendo.co.jp").
     */
    public static function isOwnDomain(Brand $brand, string $domain): bool
    {
        if (Domains::matches('https://' . $domain, $brand->domains ?? [])) {
            return true;
        }

        $label = explode('.', (string) Domains::registrable($domain))[0];

        foreach (static::ownNames($brand) as $own) {
            $compact = str_replace(' ', '', $own);

            if (mb_strlen($compact) >= 3 && $label === $compact) {
                return true;
            }
        }

        return false;
    }

    /**
     * Built-in platforms plus the configured and user-ignored domains.
     *
     * @return array<string>
     */
    public static function ignoredDomains(): array
    {
        return array_values(array_unique(array_filter([
            ...self::PLATFORM_DOMAINS,
            ...(array) config('ai-visibility.discovery.ignored_domains', []),
            ...(array) app(Settings::class)->get('discovery.ignored_domains', []),
        ])));
    }

    /**
     * Whether a name is just one of the ignored platforms ("Reddit", "G2", "YouTube").
     *
     * @param  array<string>|null  $domains  Ignored domains; default all of them.
     */
    public static function isPlatformName(string $name, ?array $domains = null): bool
    {
        $compact = str_replace(' ', '', Text::normalize($name));

        if ($compact === '') {
            return false;
        }

        foreach ($domains ?? static::ignoredDomains() as $domain) {
            $domain = strtolower((string) $domain);

            if ($compact === explode('.', $domain)[0] || $compact === str_replace('.', '', $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The non-competitor source category of a domain (review site, forum,
     * publisher…), from the curated config lists; null when it has none.
     */
    public static function nonCompetitorCategory(string $domain): ?string
    {
        $host = Domains::host($domain);

        if (! $host) {
            return null;
        }

        // Same matching as SourceCategory::categorize().
        foreach (config('ai-visibility.source_categories', []) as $category => $domains) {
            if (! in_array($category, self::NON_COMPETITOR_CATEGORIES, true)) {
                continue;
            }

            foreach ((array) $domains as $entry) {
                $matches = str_starts_with($entry, '.')
                    ? (str_ends_with($host, $entry) || $host === ltrim($entry, '.'))
                    : ($host === $entry || str_ends_with($host, '.' . $entry));

                if ($matches) {
                    return $category;
                }
            }
        }

        return null;
    }
}
