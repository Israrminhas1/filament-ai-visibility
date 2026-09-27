<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\WebsiteProfile;

/**
 * "Fill in from the website" for brand forms: the setup wizard and the brand
 * page. Only empty fields are filled, so nothing typed is overwritten.
 */
class FillsBrandFromWebsite
{
    /**
     * @param  string  $prefix  State path of the brand fields, e.g. "brand." in the wizard.
     * @return string|null The normalised domain that was read.
     */
    public static function fill(?string $website, Get $get, Set $set, string $prefix = ''): ?string
    {
        $domain = Brand::normalizeDomain((string) $website);

        if (! $domain) {
            Notification::make()->title('Enter a website first')->warning()->send();

            return null;
        }

        $details = app(WebsiteProfile::class)->brandDetails($domain);

        if (! $details) {
            Notification::make()->title('Could not read ' . $domain)->body('Fill in the details manually.')->warning()->send();

            return null;
        }

        if (blank($get($prefix . 'name')) && $details['name']) {
            $set($prefix . 'name', $details['name']);

            // Keep the full legal name as another name, so it still counts as a mention.
            if ($details['aliases'] !== []) {
                $set($prefix . 'aliases', array_values(array_unique([...($get($prefix . 'aliases') ?? []), ...$details['aliases']])));
            }
        }

        if (blank($get($prefix . 'description')) && $details['description']) {
            $set($prefix . 'description', $details['description']);
        }

        Notification::make()->title('Details filled in from ' . $domain)->body('Check them before saving.')->success()->send();

        return $domain;
    }
}
