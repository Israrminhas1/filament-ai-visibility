<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

/**
 * Every alert sent, kept as an inbox.
 */
class AlertEvent extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'alert_events';

    protected $casts = [
        'payload' => 'array',
        'read_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
