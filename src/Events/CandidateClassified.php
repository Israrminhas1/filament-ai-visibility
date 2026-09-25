<?php

namespace IsrarMinhas\FilamentAiVisibility\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;

class CandidateClassified
{
    use Dispatchable;

    public function __construct(
        public readonly Candidate $candidate,
    ) {}
}
