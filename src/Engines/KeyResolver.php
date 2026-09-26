<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use Illuminate\Support\Facades\Log;
use IsrarMinhas\FilamentAiVisibility\Models\ProviderKey;
use IsrarMinhas\FilamentAiVisibility\Support\AiMonitor;
use Throwable;

/**
 * Finds the API key for an engine: the plugin's own stored key first, then
 * AI Monitor (when installed), then the environment.
 */
class KeyResolver
{
    /**
     * Stored keys already reported as undecryptable in this process, so the
     * warning is logged once and not on every resolution.
     *
     * @var array<string, true>
     */
    protected static array $reported = [];

    public function __construct(
        protected EngineRegistry $engines,
    ) {}

    public function resolve(string $engine): ?string
    {
        return $this->source($engine)['key'];
    }

    public function has(string $engine): bool
    {
        return filled($this->resolve($engine));
    }

    /**
     * Where the key comes from, for display: "panel", "ai-monitor", "env" or null.
     *
     * @return array{key: ?string, source: ?string}
     */
    public function source(string $engine): array
    {
        $credential = $this->credential($engine);

        $stored = ProviderKey::query()
            ->where('engine', $credential)
            ->where('is_active', true)
            ->first();

        if ($stored && filled($key = $this->decrypt($stored))) {
            return ['key' => $key, 'source' => 'panel'];
        }

        if (AiMonitor::installed() && $this->engines->has($engine)) {
            foreach ($this->engines->get($engine)->aiMonitorProviders() as $provider) {
                if (filled($key = AiMonitor::key($provider))) {
                    return ['key' => $key, 'source' => 'ai-monitor'];
                }
            }
        }

        if (filled($key = config("ai-visibility.keys.{$credential}"))) {
            return ['key' => $key, 'source' => 'env'];
        }

        return ['key' => null, 'source' => null];
    }

    /**
     * A key that can no longer be decrypted (the APP_KEY was rotated) counts as missing.
     */
    protected function decrypt(ProviderKey $stored): ?string
    {
        try {
            return $stored->api_key;
        } catch (Throwable $e) {
            // A key saved again since (same row, new updated_at) is reported afresh.
            $id = $stored->getKey() . ':' . $stored->updated_at?->getTimestamp();

            if (isset(static::$reported[$id])) {
                return null;
            }

            static::$reported[$id] = true;

            Log::warning("AI Visibility: the stored API key for [{$stored->engine}] could not be decrypted; add it again. " . $e->getMessage());

            return null;
        }
    }

    public function store(string $engine, string $apiKey): ProviderKey
    {
        return ProviderKey::query()->updateOrCreate(
            ['engine' => $this->credential($engine)],
            ['api_key' => trim($apiKey), 'is_active' => true],
        );
    }

    public function remove(string $engine): void
    {
        ProviderKey::query()->where('engine', $this->credential($engine))->delete();
    }

    /**
     * Engines that share a provider share one stored key (e.g. both Google engines use "serpapi").
     */
    protected function credential(string $engine): string
    {
        return $this->engines->has($engine) ? $this->engines->get($engine)->credentialKey() : $engine;
    }
}
