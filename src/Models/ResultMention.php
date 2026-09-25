<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultMention extends Model
{
    protected string $baseTable = 'mentions';

    public $timestamps = false;

    protected $casts = [
        'descriptors' => 'array',
        'sentiment_score' => 'float',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class, 'subject_id');
    }

    public function label(): string
    {
        return $this->subject_type === 'brand'
            ? ($this->result?->brand?->name ?? $this->name_matched)
            : ($this->competitor?->name ?? $this->name_matched);
    }
}
