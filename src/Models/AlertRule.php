<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertType;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

class AlertRule extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'alert_rules';

    protected $casts = [
        'type' => AlertType::class,
        'config' => 'array',
        'channels' => 'array',
        'is_active' => 'bool',
        'last_triggered_at' => 'datetime',
    ];

    protected $attributes = [
        'cooldown_hours' => 24,
        'is_active' => true,
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $this->type->defaults()[$key] ?? $default;
    }

    public function label(): string
    {
        return $this->name ?: $this->type->getLabel();
    }
}
