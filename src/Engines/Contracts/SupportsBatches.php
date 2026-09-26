<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines\Contracts;

use IsrarMinhas\FilamentAiVisibility\Engines\BatchStatus;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;

/**
 * Engines whose provider offers a cheaper batch API (economy mode).
 */
interface SupportsBatches
{
    /**
     * @param  array<string, EngineRequest>  $requests  Keyed by custom ID.
     * @return string The provider's batch ID.
     *
     * @throws EngineRequestFailed
     */
    public function submitBatch(string $apiKey, array $requests): string;

    /**
     * @throws EngineRequestFailed
     */
    public function batchStatus(string $apiKey, string $batchId): BatchStatus;

    /**
     * @return iterable<string, EngineResponse|EngineRequestFailed> Keyed by custom ID.
     *
     * @throws EngineRequestFailed
     */
    public function batchResults(string $apiKey, string $batchId): iterable;
}
