<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

class Run extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'runs';

    protected $casts = [
        'status' => RunStatus::class,
        'trigger' => RunTrigger::class,
        'engines' => 'array',
        'estimated_cost_usd' => 'float',
        'cost_usd' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    public function processed(): int
    {
        return $this->results_done + $this->results_failed + $this->results_skipped;
    }

    public function progress(): int
    {
        return $this->results_total > 0 ? (int) floor($this->processed() / $this->results_total * 100) : 100;
    }
}
