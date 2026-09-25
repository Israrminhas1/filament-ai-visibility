<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Carbon\CarbonInterface;

class Heartbeat extends Model
{
    protected string $baseTable = 'heartbeats';

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'beat_at' => 'datetime',
    ];

    public static function beat(string $name): void
    {
        static::query()->updateOrInsert(['name' => $name], ['beat_at' => now()]);
    }

    public static function lastBeat(string $name): ?CarbonInterface
    {
        return static::query()->find($name)?->beat_at;
    }
}
