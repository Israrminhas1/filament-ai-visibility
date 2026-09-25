<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

/**
 * A company or site found in answers that might be a competitor.
 */
class Candidate extends Model
{
    use BelongsToTenant;

    public const STATUS_NEW = 'new';

    public const STATUS_CLASSIFIED = 'classified';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    protected string $baseTable = 'candidates';

    protected $casts = [
        'engines' => 'array',
        'label' => CompetitorLabel::class,
        'avg_position' => 'float',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'classified_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(Classification::class)->latest('id');
    }

    public function latestClassification(): HasOne
    {
        return $this->hasOne(Classification::class)->latestOfMany();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_CLASSIFIED], true);
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_CLASSIFIED => 'To review',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_IGNORED => 'Ignored',
        ];
    }
}
