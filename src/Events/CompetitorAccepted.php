<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;

class CompetitorAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly Candidate $candidate,
        public readonly Competitor $competitor,
    ) {}
}
