<?php

namespace IsrarMinhas\FilamentAiVisibility\Exceptions;

use RuntimeException;

class LimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $limit,
        public readonly int $max,
        string $message,
    ) {
        parent::__construct($message);
    }
}
