<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords;

final class SourceTestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        /** True when the credentials themselves were rejected. */
        public readonly bool $credentialsRejected = false,
    ) {}

    public static function ok(string $message = 'Connected'): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message, bool $credentialsRejected = false): self
    {
        return new self(false, SourceFailed::redact($message), $credentialsRejected);
    }
}
