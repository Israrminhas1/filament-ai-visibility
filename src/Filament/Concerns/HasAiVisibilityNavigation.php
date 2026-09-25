<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Concerns;

use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

/**
 * Shared navigation settings. Classes using this trait define
 * `protected static int $aiVisibilitySort`.
 */
trait HasAiVisibilityNavigation
{
    public static function getNavigationGroup(): ?string
    {
        if ($plugin = AiVisibilityPlugin::current()) {
            return $plugin->getNavigationGroup();
        }

        return 'AI Visibility';
    }

    public static function getNavigationSort(): ?int
    {
        return (AiVisibilityPlugin::current()?->getNavigationSort() ?? 0) + static::$aiVisibilitySort;
    }
}
