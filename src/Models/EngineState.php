<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

class EngineState extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'engine_states';

    protected $casts = [
        'status' => EngineStatus::class,
        'reason' => PauseReason::class,
        'last_error' => 'array',
        'paused_at' => 'datetime',
        'resume_after' => 'datetime',
        'next_probe_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'active',
        'consecutive_failures' => 0,
    ];

    public function isUsable(): bool
    {
        return in_array($this->status, [EngineStatus::Active, EngineStatus::Degraded], true);
    }
}
