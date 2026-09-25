<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Asks the AI helper for likely competitors of a new brand (setup wizard).
 */
class CompetitorSuggester
{
    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
    ) {}

    /**
     * @param  array<string>  $existing  Names already listed.
     * @return array<int, array{name: string, domain: ?string, reason: ?string}>
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable
     */
    public function suggest(Brand $brand, array $existing = [], int $count = 8): array
    {
        $data = $this->helper->json(Usage::PURPOSE_CLASSIFICATION, $this->instructions->render(Instructions::SUGGEST_COMPETITORS, [
            'brand' => $brand->name,
            'domain' => $brand->primaryDomain() ?? 'unknown',
            'description' => $brand->description ?: 'not given',
            'industry' => $brand->industry ?: 'not given',
            'market' => $brand->market ?: 'not given',
            'existing' => $existing ? implode(', ', $existing) : 'none',
            'count' => (string) $count,
        ]), $brand, maxTokens: 1500);

        $known = collect([...$existing, ...$brand->names()])->map(fn ($name) => Text::normalize($name))->flip();

        return collect($data['competitors'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
            ->map(fn ($row) => [
                'name' => Text::squish((string) $row['name']),
                'domain' => Brand::normalizeDomain((string) ($row['domain'] ?? '')),
                'reason' => $row['reason'] ?? null,
            ])
            ->reject(fn ($row) => $known->has(Text::normalize($row['name'])))
            ->unique(fn ($row) => Text::normalize($row['name']))
            ->take($count)
            ->values()
            ->all();
    }
}
