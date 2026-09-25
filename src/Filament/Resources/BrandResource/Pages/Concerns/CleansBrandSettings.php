<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\Concerns;

use Illuminate\Support\Arr;

trait CleansBrandSettings
{
    /**
     * Keep only filled overrides, so empty fields fall back to the global settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function cleanSettings(array $data): array
    {
        $settings = [];

        foreach (config('ai-visibility.brand_overridable', []) as $key) {
            $value = Arr::get($data['settings'] ?? [], $key);

            if (filled($value)) {
                Arr::set($settings, $key, is_numeric($value) ? $value + 0 : $value);
            }
        }

        $data['settings'] = $settings ?: null;

        return $data;
    }
}
