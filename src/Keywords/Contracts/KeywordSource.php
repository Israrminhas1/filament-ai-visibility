<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords\Contracts;

use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordData;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceTestResult;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;

/**
 * A place keywords come from. Register your own with
 * AiVisibilityPlugin::make()->keywordSource(MySource::class).
 */
interface KeywordSource
{
    /**
     * Stored as the connection type, e.g. "serpapi".
     */
    public function key(): string;

    public function label(): string;

    /**
     * One line shown when choosing a source.
     */
    public function description(): string;

    /**
     * Form fields for credentials (stored encrypted under `credentials.*`)
     * and options (under `config.*`).
     *
     * @return array<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    public function formFields(): array;

    public function test(Connection $connection): SourceTestResult;

    /**
     * @return iterable<KeywordData>
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed
     */
    public function fetch(Connection $connection): iterable;
}
