<?php

namespace IsrarMinhas\FilamentAiVisibility\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * For models with their own `tenant_id` column.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = Tenancy::currentId();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function ($model) {
            if ($model->tenant_id === null && ($tenantId = Tenancy::currentId()) !== null) {
                $model->tenant_id = $tenantId;
            }
        });
    }
}
