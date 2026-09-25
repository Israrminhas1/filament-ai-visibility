<?php

/*
 * A stand-in for israrminhas/filament-aimonitor's key manager, so the optional
 * integration can be tested without installing that package. Only loaded by
 * tests that need it.
 */

namespace Filament\AiMonitor\Services;

if (! class_exists(AiKeyManager::class)) {
    class AiKeyManager
    {
        /**
         * @var array<string, string>
         */
        public static array $keys = [];

        public function getKey(string $provider): ?string
        {
            return static::$keys[strtolower($provider)] ?? null;
        }
    }
}
