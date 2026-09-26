<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;

/**
 * Enforces the limits from Settings (and brand overrides). Called from
 * model saves, actions, importers and jobs, so limits hold everywhere,
 * not only in the UI.
 */
class Limits
{
    public function __construct(
        protected Settings $settings,
    ) {}

    public function maxBrands(): ?int
    {
        return $this->int($this->settings->get('limits.max_brands'));
    }

    public function maxCompetitors(Brand $brand): ?int
    {
        return $this->int($brand->setting('limits.max_competitors_per_brand'));
    }

    public function maxActivePrompts(Brand $brand): ?int
    {
        return $this->int($brand->setting('limits.max_active_prompts_per_brand'));
    }

    public function maxKeywords(Brand $brand): ?int
    {
        return $this->int($this->settings->get('limits.max_keywords_per_brand'));
    }

    public function remainingActivePrompts(Brand $brand, ?int $ignorePromptId = null): ?int
    {
        $max = $this->maxActivePrompts($brand);

        if ($max === null) {
            return null;
        }

        $active = $brand->activePrompts()
            ->when($ignorePromptId, fn ($query) => $query->whereKeyNot($ignorePromptId))
            ->count();

        return max(0, $max - $active);
    }

    public function remainingKeywords(Brand $brand): ?int
    {
        $max = $this->maxKeywords($brand);

        return $max === null ? null : max(0, $max - $brand->keywords()->count());
    }

    public function ensureCanCreateBrand(): void
    {
        $max = $this->maxBrands();

        if ($max !== null && Brand::query()->count() >= $max) {
            throw new LimitExceeded('max_brands', $max, "You can track up to {$max} brands. Raise the limit in Settings to add more.");
        }
    }

    public function ensureCanAddCompetitor(Brand $brand): void
    {
        $max = $this->maxCompetitors($brand);

        if ($max !== null && $brand->competitors()->count() >= $max) {
            throw new LimitExceeded('max_competitors_per_brand', $max, "{$brand->name} already has the maximum of {$max} competitors.");
        }
    }

    public function ensureCanActivatePrompts(Brand $brand, int $count = 1, ?int $ignorePromptId = null): void
    {
        $remaining = $this->remainingActivePrompts($brand, $ignorePromptId);

        if ($remaining !== null && $count > $remaining) {
            $max = $this->maxActivePrompts($brand);

            throw new LimitExceeded('max_active_prompts_per_brand', $max, $remaining === 0
                ? "{$brand->name} already has the maximum of {$max} active prompts. Pause some prompts or raise the limit in Settings."
                : "Only {$remaining} more active prompts are allowed for {$brand->name} (limit {$max}).");
        }
    }

    public function ensureCanAddKeywords(Brand $brand, int $count = 1): void
    {
        $max = $this->maxKeywords($brand);

        if ($max !== null && $brand->keywords()->count() + $count > $max) {
            throw new LimitExceeded('max_keywords_per_brand', $max, "{$brand->name} can have up to {$max} keywords.");
        }
    }

    protected function int(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }
}
