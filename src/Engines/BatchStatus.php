<?php

namespace IsrarMinhas\FilamentAiVisibility\Engines;

final class BatchStatus
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly ?string $message = null,
    ) {}

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }
}
