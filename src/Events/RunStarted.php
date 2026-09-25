<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Models\Run;

class RunStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Run $run,
    ) {}
}
