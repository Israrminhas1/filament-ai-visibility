<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords;

use RuntimeException;

class SourceFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $credentialsRejected = false,
    ) {
        parent::__construct($message);
    }
}
