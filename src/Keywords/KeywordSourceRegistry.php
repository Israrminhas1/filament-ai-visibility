<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords;

use InvalidArgumentException;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;

class KeywordSourceRegistry
{
    /**
     * @var array<string, class-string<KeywordSource>|KeywordSource>
     */
    protected array $sources = [];

    /**
     * @param  class-string<KeywordSource>|KeywordSource  $source
     */
    public function register(string | KeywordSource $source): void
    {
        $instance = is_string($source) ? app($source) : $source;
        $this->sources[$instance->key()] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    public function get(string $key): KeywordSource
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("AI Visibility keyword source [{$key}] is not registered.");
        }

        return is_string($this->sources[$key]) ? $this->sources[$key] = app($this->sources[$key]) : $this->sources[$key];
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        return collect(array_keys($this->sources))->mapWithKeys(fn ($key) => [$key => $this->get($key)->label()])->all();
    }
}
