<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class Citation extends Model
{
    protected string $baseTable = 'citations';

    public $timestamps = false;

    protected $casts = [
        'is_brand' => 'bool',
        'cited' => 'bool',
    ];

    protected static ?bool $hasCitedColumn = null;

    /**
     * Whether the `cited` migration has run; an upgrade without it keeps recording answers.
     */
    public static function hasCitedColumn(): bool
    {
        return static::$hasCitedColumn ??= Schema::hasColumn((new static)->getTable(), 'cited');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
