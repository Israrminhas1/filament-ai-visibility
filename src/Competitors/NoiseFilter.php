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
     * Default platforms that are not competitors; replaced by
     * config('ai-visibility.discovery.platform_domains') when that is set.
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
     * Whether a name is the brand or one of its products: the brand name or
     * an alias itself (any case, without legal endings), or a name that
     * starts with it as a whole word, written the same way ("Nintendo Switch 2").
     *
     * Only names of 4+ characters (or all-caps acronyms like "HP") count as
     * a start, so "Go" does not claim "Go Daddy". A different company that
     * starts with the brand's exact name ("Delta Faucet" for Delta) is still
     * treated as the brand's own; the user can track it by hand.
     */
    public static function isOwnName(Brand $brand, string $name): bool
    {
        $base = static::baseName($name);

        if ($base === '') {
            return false;
        }

        $written = Text::squish(Text::clean($name));

        foreach ($brand->names() as $own) {
            $ownBase = static::baseName($own);

            if (mb_strlen($ownBase) < 2) {
                continue;
            }

            if ($base === $ownBase) {
                return true;
            }

            // The name as written, without legal endings: "HP Inc." → "HP".
            $words = explode(' ', Text::squish(Text::clean($own)));
            $term = rtrim(implode(' ', array_slice($words, 0, count(explode(' ', $ownBase)))), ' ,.');
            $distinct = mb_strlen($term) >= 4 || (mb_strlen($term) >= 2 && $term === mb_strtoupper($term) && preg_match('/\p{Lu}/u', $term));

            if ($distinct && preg_match('/^' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $written)) {
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
     * Platforms that are never candidates: config('ai-visibility.discovery.platform_domains')
     * when set (it replaces the built-in list), otherwise PLATFORM_DOMAINS.
     * For a brand, platforms it competes with are left out (see allowedPlatforms()).
     *
     * @return array<string>
     */
    public static function platformDomains(?Brand $brand = null): array
    {
        $configured = config('ai-visibility.discovery.platform_domains');
        $domains = array_values(array_filter(array_map(
            fn ($domain) => is_string($domain) ? strtolower(trim($domain)) : '',
            is_array($configured) ? $configured : self::PLATFORM_DOMAINS,
        )));

        if (! $brand) {
            return $domains;
        }

        $allowed = static::allowedPlatforms($brand);

        return array_values(array_filter($domains, fn ($domain) => ! Domains::matches('https://' . $domain, $allowed)));
    }

    /**
     * Platforms that are real competitors for this brand: the ones in its
     * "discovery.allow_platforms" setting, and any it already tracks as a
     * competitor (a code host tracking GitHub, a shop tracking Amazon).
     *
     * @return array<string>
     */
    public static function allowedPlatforms(Brand $brand): array
    {
        $allowed = array_filter((array) $brand->setting('discovery.allow_platforms', []), 'is_string');

        foreach ($brand->competitors()->get() as $competitor) {
            array_push($allowed, ...array_filter((array) ($competitor->domains ?? []), 'is_string'));
        }

        return array_values(array_unique(array_filter(array_map(fn ($domain) => Domains::host($domain), $allowed))));
    }

    /**
     * Platforms plus the configured and user-ignored domains.
     *
     * @return array<string>
     */
    public static function ignoredDomains(?Brand $brand = null): array
    {
        return array_values(array_unique(array_filter([
            ...static::platformDomains($brand),
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
