<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

/**
 * Public facts about a website, fetched as classification evidence.
 * Shared across tenants: it only holds public web content.
 */
class DomainProfile extends Model
{
    protected string $baseTable = 'domain_profiles';

    protected $casts = [
        'headings' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function isFresh(): bool
    {
        return $this->fetched_at?->gt(now()->subDays((int) config('ai-visibility.discovery.evidence_days', 30))) ?? false;
    }
}
