<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use InvalidArgumentException;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine;

class EngineRegistry
{
    /**
     * @var array<string, class-string<Engine>|Engine>
     */
    protected array $engines = [];

    /**
     * @param  class-string<Engine>|Engine  $engine
     */
    public function register(string | Engine $engine): void
    {
        $instance = $this->resolve($engine);

        $this->engines[$instance->key()] = $instance;
    }

    public function forget(string $key): void
    {
        unset($this->engines[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->engines[$key]);
    }

    public function get(string $key): Engine
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("AI Visibility engine [{$key}] is not registered.");
        }

        return $this->engines[$key] = $this->resolve($this->engines[$key]);
    }

    /**
     * @return array<string, Engine>
     */
    public function all(): array
    {
        return array_map(fn ($engine) => $this->resolve($engine), $this->engines);
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        return array_map(fn (Engine $engine) => $engine->label(), $this->all());
    }

    protected function resolve(string | Engine $engine): Engine
    {
        return is_string($engine) ? app($engine) : $engine;
    }
}
