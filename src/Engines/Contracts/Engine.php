<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Contracts;

use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyTestResult;

interface Engine
{
    /**
     * Unique key, e.g. "openai".
     */
    public function key(): string;

    /**
     * Name shown in the panel, e.g. "OpenAI (ChatGPT)".
     */
    public function label(): string;

    /**
     * Model used for tracked prompts unless configured otherwise.
     */
    public function defaultTrackingModel(): string;

    /**
     * Cheaper model used for analysis, classification and generation.
     */
    public function defaultHelperModel(): string;

    /**
     * Suggested models for the model picker. Users may also type any model.
     *
     * @return array<string>
     */
    public function suggestedModels(): array;

    /**
     * Keys this engine may be stored under in AI Monitor.
     *
     * @return array<string>
     */
    public function aiMonitorProviders(): array;

    /**
     * Check that a key works with a cheap request.
     */
    public function testKey(string $apiKey): KeyTestResult;

    /**
     * Answer a tracked prompt the way the assistant would for its users,
     * with web search enabled, returning the answer and its sources.
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed
     */
    public function ask(EngineRequest $request): EngineResponse;

    /**
     * A plain completion without web search, for helper features
     * (classification, extraction, generation).
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed
     */
    public function complete(CompletionRequest $request): CompletionResponse;
}
