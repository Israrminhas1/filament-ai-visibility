<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

/**
 * A keyword source connected for a brand (SerpAPI, Search Console, DataForSEO…).
 * Credentials are stored encrypted and never sent back to the browser.
 */
class Connection extends Model
{
    use BelongsToTenant;

    public const CONNECTED = 'connected';

    public const NEEDS_REAUTH = 'needs_reauth';

    public const ERROR = 'error';

    public const DISABLED = 'disabled';

    protected string $baseTable = 'connections';

    protected $casts = [
        'credentials' => 'encrypted:array',
        'config' => 'array',
        'last_synced_at' => 'datetime',
        'next_sync_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function credential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::CONNECTED => 'Connected',
            self::NEEDS_REAUTH => 'Needs new credentials',
            self::ERROR => 'Error',
            self::DISABLED => 'Disabled',
        ];
    }
}
