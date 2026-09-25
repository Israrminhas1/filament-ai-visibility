<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class EngineResumed
{
    use Dispatchable;

    public readonly int | string | null $tenantId;

    public function __construct(
        public readonly string $engine,
    ) {
        $this->tenantId = Tenancy::currentId();
    }
}
