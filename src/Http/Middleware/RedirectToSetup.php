<?php

namespace IsrarMinhas\FilamentAiVisibility\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users to the setup wizard until it is complete. Only AI Visibility
 * screens are affected; the rest of the panel works as usual.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = (string) $request->route()?->getName();

        if (! preg_match('/\.ai-visibility(\.|$)/', $route) || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $plugin = AiVisibilityPlugin::current();

        if (! $plugin?->hasSetupWizard()) {
            return $next($request);
        }

        foreach ([Setup::class, Health::class] as $allowed) {
            if ($route === $this->routeName($allowed)) {
                return $next($request);
            }
        }

        if (app(Settings::class)->isSetupComplete()) {
            return $next($request);
        }

        $url = AiVisibilityPlugin::pageUrl(Setup::class);

        return $url ? redirect()->to($url) : $next($request);
    }

    protected function routeName(string $page): ?string
    {
        try {
            return $page::getRouteName();
        } catch (\Throwable) {
            return null;
        }
    }
}
