<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;

final class KeyTestResult
{
    /**
     * @param  array<string>  $models  Models available to the key, when the provider reports them.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?PauseReason $reason = null,
        public readonly array $models = [],
    ) {}

    public static function ok(string $message = 'Connected', array $models = []): self
    {
        return new self(true, $message, null, $models);
    }

    public static function failed(PauseReason $reason, ?string $detail = null): self
    {
        return new self(false, $reason->getLabel() . ($detail ? ": {$detail}" : ''), $reason);
    }
}
