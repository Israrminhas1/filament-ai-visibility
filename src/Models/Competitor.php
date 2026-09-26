<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use IsrarMinhas\FilamentAiVisibility\Jobs\RedetectBrandJob;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToBrand;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;

class Competitor extends Model
{
    use BelongsToBrand;

    protected string $baseTable = 'competitors';

    protected $casts = [
        'aliases' => 'array',
        'domains' => 'array',
        'exclusions' => 'array',
        'is_active' => 'bool',
    ];

    /**
     * Distinct colours for charts, assigned in order.
     */
    public const PALETTE = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16'];

    protected static function booted(): void
    {
        static::saving(function (Competitor $competitor) {
            $competitor->domains = Brand::normalizeDomains($competitor->domains ?? []);
            $competitor->aliases = array_values(array_unique(array_filter(array_map('trim', $competitor->aliases ?? []))));
            $competitor->exclusions = array_values(array_filter(array_map('trim', $competitor->exclusions ?? [])));
        });

        static::creating(function (Competitor $competitor) {
            if ($competitor->brand) {
                app(Limits::class)->ensureCanAddCompetitor($competitor->brand);
            }

            if (blank($competitor->color)) {
                $count = static::withoutGlobalScopes()->where('brand_id', $competitor->brand_id)->count();
                $competitor->color = static::PALETTE[$count % count(static::PALETTE)];
            }
        });

        // Past answers are re-checked so reports include (or drop) this competitor.
        static::created(fn (Competitor $competitor) => RedetectBrandJob::dispatchFor($competitor->brand));
        static::deleted(fn (Competitor $competitor) => RedetectBrandJob::dispatchFor($competitor->brand));
        static::updated(function (Competitor $competitor) {
            if ($competitor->wasChanged(['name', 'aliases', 'domains', 'exclusions', 'is_active'])) {
                RedetectBrandJob::dispatchFor($competitor->brand);
            }
        });
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_filter([$this->name, ...($this->aliases ?? [])])));
    }
}
