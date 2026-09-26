<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

/**
 * A group of answers sent to a provider's batch API (economy mode).
 */
class Batch extends Model
{
    use BelongsToTenant;

    // Being sent to the provider; the results must not be sent again.
    public const SUBMITTING = 'submitting';

    public const SUBMITTED = 'submitted';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected string $baseTable = 'batches';

    protected $casts = [
        'result_ids' => 'array',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
