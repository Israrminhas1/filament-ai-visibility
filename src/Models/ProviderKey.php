<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;
use Throwable;

class ProviderKey extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'provider_keys';

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'bool',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['api_key'];

    /**
     * "••••••••3xYz"
     */
    public function maskedKey(): string
    {
        try {
            $key = (string) $this->api_key;
        } catch (Throwable) {
            return 'Unable to decrypt';
        }

        return str_repeat('•', 8) . (strlen($key) > 8 ? substr($key, -4) : '');
    }
}
