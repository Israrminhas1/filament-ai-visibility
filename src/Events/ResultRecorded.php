<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

class ResultRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly Result $result,
    ) {}
}
