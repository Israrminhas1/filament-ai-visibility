<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class EnginePaused
{
    use Dispatchable;

    public readonly int | string | null $tenantId;

    public function __construct(
        public readonly string $engine,
        public readonly PauseReason $reason,
        public readonly ?string $message = null,
    ) {
        $this->tenantId = Tenancy::currentId();
    }
}
