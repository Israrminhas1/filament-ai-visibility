<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Concerns;

use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

/**
 * Shared navigation and access settings. Classes using this trait define
 * `protected static int $aiVisibilitySort`.
 *
 * The sort also decides the navigation group when groups are split:
 * below 10 is reports, 10–79 is tracking and 80 or more is admin. Screens that
 * require settings access (such as Setup) are always admin.
 */
trait HasAiVisibilityNavigation
{
    public static function getNavigationGroup(): ?string
    {
        if ($plugin = AiVisibilityPlugin::current()) {
            return $plugin->getNavigationGroupFor(static::aiVisibilityArea());
        }

        return 'AI Visibility';
    }

    public static function getNavigationSort(): ?int
    {
        return (AiVisibilityPlugin::current()?->getNavigationSort() ?? 0) + static::$aiVisibilitySort;
    }

    /**
     * "reports", "tracking" or "admin".
     */
    public static function aiVisibilityArea(): string
    {
        return match (true) {
            static::requiresSettingsAccess() => 'admin',
            static::$aiVisibilitySort < 10 => 'reports',
            static::$aiVisibilitySort < 80 => 'tracking',
            default => 'admin',
        };
    }

    public static function canAccess(): bool
    {
        $allowed = static::requiresSettingsAccess()
            ? AiVisibilityPlugin::userCanManage()
            : (AiVisibilityPlugin::current()?->isAuthorized() ?? true);

        return $allowed && parent::canAccess();
    }

    /**
     * Screens that change settings, keys or budgets return true.
     */
    public static function requiresSettingsAccess(): bool
    {
        return false;
    }
}
