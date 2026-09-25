<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

class Result extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'results';

    protected $casts = [
        'status' => ResultStatus::class,
        'brand_mentioned' => 'bool',
        'brand_cited' => 'bool',
        'cost_usd' => 'float',
        'ran_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(ResultMention::class)->orderBy('position');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class)->orderBy('position');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', ResultStatus::Success);
    }
}
