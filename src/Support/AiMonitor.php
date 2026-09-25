<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Throwable;

/**
 * Optional integration with israrminhas/filament-aimonitor.
 */
class AiMonitor
{
    protected const KEY_MANAGER = 'Filament\\AiMonitor\\Services\\AiKeyManager';

    protected const USAGE_LOGGER = 'Filament\\AiMonitor\\Services\\AiUsageLogger';

    public static function installed(): bool
    {
        return class_exists(static::KEY_MANAGER);
    }

    public static function key(string $provider): ?string
    {
        if (! static::installed()) {
            return null;
        }

        try {
            return app(static::KEY_MANAGER)->getKey($provider);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Log a call in AI Monitor. Failures never break AI Visibility.
     *
     * @param  array<string, mixed>  $data
     */
    public static function log(array $data): void
    {
        if (! class_exists(static::USAGE_LOGGER)) {
            return;
        }

        try {
            app(static::USAGE_LOGGER)->log($data);
        } catch (Throwable) {
            //
        }
    }
}
