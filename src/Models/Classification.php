<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;

class Classification extends Model
{
    protected string $baseTable = 'classifications';

    protected $casts = [
        'label' => CompetitorLabel::class,
        'override_label' => CompetitorLabel::class,
        'is_direct_competitor' => 'bool',
        'evidence' => 'array',
        'overridden_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * The label that applies: the user's correction if there is one.
     */
    public function effectiveLabel(): CompetitorLabel
    {
        return $this->override_label ?? $this->label;
    }
}
