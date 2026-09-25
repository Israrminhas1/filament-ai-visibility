<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Models\Run;

class RunCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Run $run,
    ) {}
}
