<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Closure;
use Filament\Facades\Filament;
use Throwable;

class Tenancy
{
    protected static ?Closure $resolver = null;

    /**
     * When set, overrides the resolved tenant (used by scheduled work to act
     * on behalf of each tenant in turn).
     */
    protected static bool $overridden = false;

    protected static int | string | null $override = null;

    /**
     * Customise how the current tenant ID is resolved, e.g. in a service provider:
     *
     *     Tenancy::resolveUsing(fn () => auth()->user()?->team_id);
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    public static function enabled(): bool
    {
        return (bool) config('ai-visibility.tenant_support', true);
    }

    public static function currentId(): int | string | null
    {
        if (! static::enabled()) {
            return null;
        }

        if (static::$overridden) {
            return static::$override;
        }

        if (static::$resolver) {
            return (static::$resolver)();
        }

        if (function_exists('tenant') && ($tenant = tenant())) {
            return method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : $tenant->id;
        }

        try {
            return Filament::getTenant()?->getKey();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run a callback as the given tenant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function as(int | string | null $tenantId, Closure $callback): mixed
    {
        $previous = [static::$overridden, static::$override];

        static::$overridden = true;
        static::$override = $tenantId;

        try {
            return $callback();
        } finally {
            [static::$overridden, static::$override] = $previous;
        }
    }
}
