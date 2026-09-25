<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Illuminate\Support\Arr;
use IsrarMinhas\FilamentAiVisibility\Models\SettingsRecord;

/**
 * Tenant settings: values saved in the panel, falling back to
 * config('ai-visibility.defaults'). Brand overrides live on Brand::setting().
 */
class Settings
{
    /**
     * @var array<string, SettingsRecord>
     */
    protected array $records = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $value = Arr::get($this->record()->settings ?? [], $key);

        if ($value !== null) {
            return $value;
        }

        return Arr::get(config('ai-visibility.defaults', []), $key, $default);
    }

    /**
     * Every setting with defaults applied, for filling the settings form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace_recursive(config('ai-visibility.defaults', []), $this->record()->settings ?? []);
    }

    /**
     * @param  array<string, mixed>  $values  Dot-notation keys or nested arrays.
     */
    public function set(array $values): void
    {
        $record = $this->record();
        $settings = $record->settings ?? [];

        foreach (static::flatten($values) as $key => $value) {
            Arr::set($settings, $key, $value);
        }

        $record->settings = $settings;
        $record->save();
    }

    /**
     * Like Arr::dot(), but lists (e.g. enabled engines) are kept whole so they
     * replace the stored list instead of being merged into it by index.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix . $key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += static::flatten($value, $path . '.');
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    public function record(): SettingsRecord
    {
        $key = (string) (Tenancy::currentId() ?? '__global');

        if (isset($this->records[$key]) && $this->records[$key]->exists) {
            return $this->records[$key];
        }

        return $this->records[$key] = SettingsRecord::query()->firstOrCreate([
            'tenant_id' => Tenancy::currentId(),
        ]);
    }

    public function isSetupComplete(): bool
    {
        return $this->record()->setup_completed_at !== null;
    }

    public function completeSetup(): void
    {
        $this->record()->forceFill(['setup_completed_at' => now()])->save();
    }

    public function setupStep(): int
    {
        return $this->record()->setup_step;
    }

    public function setSetupStep(int $step): void
    {
        $record = $this->record();

        if ($step > $record->setup_step) {
            $record->forceFill(['setup_step' => $step])->save();
        }
    }

    public function killSwitch(): bool
    {
        return $this->record()->kill_switch;
    }

    public function setKillSwitch(bool $on): void
    {
        $this->record()->forceFill(['kill_switch' => $on])->save();
    }

    public function flush(): void
    {
        $this->records = [];
    }
}
