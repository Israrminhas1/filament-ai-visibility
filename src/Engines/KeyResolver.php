<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use IsrarMinhas\FilamentAiVisibility\Models\ProviderKey;
use IsrarMinhas\FilamentAiVisibility\Support\AiMonitor;

/**
 * Finds the API key for an engine: the plugin's own stored key first, then
 * AI Monitor (when installed), then the environment.
 */
class KeyResolver
{
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
        $stored = ProviderKey::query()
            ->where('engine', $engine)
            ->where('is_active', true)
            ->first();

        if ($stored && filled($stored->api_key)) {
            return ['key' => $stored->api_key, 'source' => 'panel'];
        }

        if (AiMonitor::installed() && $this->engines->has($engine)) {
            foreach ($this->engines->get($engine)->aiMonitorProviders() as $provider) {
                if (filled($key = AiMonitor::key($provider))) {
                    return ['key' => $key, 'source' => 'ai-monitor'];
                }
            }
        }

        if (filled($key = config("ai-visibility.keys.{$engine}"))) {
            return ['key' => $key, 'source' => 'env'];
        }

        return ['key' => null, 'source' => null];
    }

    public function store(string $engine, string $apiKey): ProviderKey
    {
        return ProviderKey::query()->updateOrCreate(
            ['engine' => $engine],
            ['api_key' => trim($apiKey), 'is_active' => true],
        );
    }

    public function remove(string $engine): void
    {
        ProviderKey::query()->where('engine', $engine)->delete();
    }
}
