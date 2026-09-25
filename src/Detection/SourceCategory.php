<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;

/**
 * Groups cited domains: the brand's own site, competitors, then the curated
 * lists in config('ai-visibility.source_categories'), otherwise "other".
 */
class SourceCategory
{
    public const OWN = 'own';

    public const COMPETITOR = 'competitor';

    public const OTHER = 'other';

    /**
     * @return array<string, string> key => label, in display order
     */
    public static function labels(): array
    {
        return [
            self::OWN => 'Your site',
            self::COMPETITOR => 'Competitors',
            'review_comparison' => 'Review & comparison',
            'forum_community' => 'Forums & communities',
            'media_publisher' => 'Media & publishers',
            'social' => 'Social & video',
            'marketplace' => 'Marketplaces & app stores',
            'wiki_reference' => 'Wikis & reference',
            'government_education' => 'Government & education',
            self::OTHER => 'Other',
        ];
    }

    public static function label(?string $category): string
    {
        return static::labels()[$category ?? self::OTHER] ?? ucfirst(str_replace('_', ' ', (string) $category));
    }

    public static function color(?string $category): string
    {
        return [
            self::OWN => '#10b981',
            self::COMPETITOR => '#ef4444',
            'review_comparison' => '#f59e0b',
            'forum_community' => '#8b5cf6',
            'media_publisher' => '#3b82f6',
            'social' => '#ec4899',
            'marketplace' => '#14b8a6',
            'wiki_reference' => '#64748b',
            'government_education' => '#0ea5e9',
        ][$category] ?? '#9ca3af';
    }

    public function categorize(string $url, Brand $brand, ?bool $isBrand = null, ?int $competitorId = null): string
    {
        if ($isBrand ?? Domains::matches($url, $brand->domains ?? [])) {
            return self::OWN;
        }

        if ($competitorId) {
            return self::COMPETITOR;
        }

        $host = Domains::host($url);

        if (! $host) {
            return self::OTHER;
        }

        foreach (config('ai-visibility.source_categories', []) as $category => $domains) {
            foreach ($domains as $domain) {
                // Entries starting with a dot are suffixes, e.g. ".gov" (and "gov.uk" itself for ".gov.uk").
                $matches = str_starts_with($domain, '.')
                    ? (str_ends_with($host, $domain) || $host === ltrim($domain, '.'))
                    : ($host === $domain || str_ends_with($host, '.' . $domain));

                if ($matches) {
                    return $category;
                }
            }
        }

        // Fall back to how the domain was classified as a candidate (review site, marketplace…).
        return $this->candidateCategories($brand)[Domains::registrable($url)] ?? self::OTHER;
    }

    /**
     * @var array<int, array<string, string>>
     */
    protected array $candidateCategories = [];

    /**
     * @return array<string, string> domain => category, for the brand's labelled candidates
     */
    protected function candidateCategories(Brand $brand): array
    {
        return $this->candidateCategories[$brand->getKey()] ??= Candidate::query()
            ->where('brand_id', $brand->getKey())
            ->whereNotNull('domain')
            ->whereNotNull('label')
            ->get(['domain', 'label'])
            ->mapWithKeys(fn (Candidate $candidate) => [$candidate->domain => $candidate->label?->sourceCategory()])
            ->filter(fn ($category) => $category !== null && ! in_array($category, [self::OWN, self::COMPETITOR], true))
            ->all();
    }
}
