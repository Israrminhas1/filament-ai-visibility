<?php

namespace IsrarMinhas\FilamentAiVisibility\Support\Health;

final class CheckResult
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $fix = null,
        public readonly bool $blocking = false,
    ) {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    public function color(): string
    {
        return match ($this->status) {
            self::OK => 'success',
            self::WARNING => 'warning',
            default => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this->status) {
            self::OK => 'heroicon-o-check-circle',
            self::WARNING => 'heroicon-o-exclamation-triangle',
            default => 'heroicon-o-x-circle',
        };
    }
}
