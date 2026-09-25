<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Citation extends Model
{
    protected string $baseTable = 'citations';

    public $timestamps = false;

    protected $casts = [
        'is_brand' => 'bool',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
