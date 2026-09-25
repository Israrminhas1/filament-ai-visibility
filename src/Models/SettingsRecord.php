<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

class SettingsRecord extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'settings';

    protected $casts = [
        'settings' => 'array',
        'kill_switch' => 'bool',
        'setup_step' => 'int',
        'setup_completed_at' => 'datetime',
    ];

    protected $attributes = [
        'setup_step' => 1,
        'kill_switch' => false,
    ];
}
