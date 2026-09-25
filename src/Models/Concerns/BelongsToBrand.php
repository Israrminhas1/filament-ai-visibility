<?php

namespace IsrarMinhas\FilamentAiVisibility\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * For models owned by a brand. They inherit the brand's tenant: when a tenant
 * is active, only records of that tenant's brands are visible.
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope('brand_tenant', function (Builder $builder) {
            if (Tenancy::currentId() !== null) {
                $builder->whereHas('brand');
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
