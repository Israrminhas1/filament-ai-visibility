<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

class Brand extends Model
{
    use BelongsToTenant;

    protected string $baseTable = 'brands';

    protected $casts = [
        'aliases' => 'array',
        'domains' => 'array',
        'exclusions' => 'array',
        'settings' => 'array',
        'is_active' => 'bool',
        'run_frequency' => RunFrequency::class,
        'last_run_at' => 'datetime',
    ];

    /**
     * Same as the column defaults, so new models behave correctly before being reloaded.
     */
    protected $attributes = [
        'is_active' => true,
        'run_frequency' => 'weekly',
    ];

    protected static function booted(): void
    {
        static::creating(fn () => app(Limits::class)->ensureCanCreateBrand());

        static::saving(function (Brand $brand) {
            $brand->domains = static::normalizeDomains($brand->domains ?? []);
            $brand->aliases = static::cleanList($brand->aliases ?? []);
            $brand->exclusions = static::cleanList($brand->exclusions ?? []);
        });
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    public function activePrompts(): HasMany
    {
        return $this->prompts()->where('status', PromptStatus::Active);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /**
     * Two-letter country code derived from the market, for engines that localise search.
     */
    public function countryCode(): ?string
    {
        $market = trim((string) $this->market);

        // Known names first, so "UK" becomes the ISO code "GB".
        if ($code = config('ai-visibility.markets.' . strtolower($market))) {
            return $code;
        }

        return preg_match('/^[A-Za-z]{2}$/', $market) ? strtoupper($market) : null;
    }

    /**
     * The name plus aliases, used for detection.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_filter([$this->name, ...($this->aliases ?? [])])));
    }

    public function primaryDomain(): ?string
    {
        return $this->domains[0] ?? null;
    }

    /**
     * A setting for this brand: its own override if set, otherwise the tenant setting.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        if (in_array($key, config('ai-visibility.brand_overridable', []), true)) {
            $override = Arr::get($this->settings ?? [], $key);

            if (filled($override)) {
                return $override;
            }
        }

        return app(Settings::class)->get($key, $default);
    }

    public function hasOverride(string $key): bool
    {
        return filled(Arr::get($this->settings ?? [], $key));
    }

    /**
     * "https://www.Acme.com/about" → "acme.com"
     *
     * @param  array<string>  $domains
     * @return array<string>
     */
    public static function normalizeDomains(array $domains): array
    {
        return collect($domains)
            ->map(fn ($domain) => static::normalizeDomain((string) $domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeDomain(string $value): ?string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || ! str_contains($host, '.')) {
            return null;
        }

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * @param  array<string>  $values
     * @return array<string>
     */
    protected static function cleanList(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => mb_strtolower($value))
            ->values()
            ->all();
    }
}
