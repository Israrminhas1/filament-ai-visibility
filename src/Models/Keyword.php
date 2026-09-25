<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToBrand;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

class Keyword extends Model
{
    use BelongsToBrand;

    protected string $baseTable = 'keywords';

    protected $casts = [
        'source' => KeywordSource::class,
        'is_branded' => 'bool',
        'metadata' => 'array',
        'avg_position' => 'float',
        'last_synced_at' => 'datetime',
    ];

    protected $attributes = [
        'source' => 'manual',
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (Keyword $keyword) {
            $keyword->keyword = Text::squish($keyword->keyword);
            $keyword->keyword_hash = Text::hash($keyword->keyword);

            if ($keyword->brand) {
                $keyword->is_branded = Text::mentionsAny($keyword->keyword, $keyword->brand->names());
            }
        });
    }

    public function prompts(): BelongsToMany
    {
        return $this->belongsToMany(Prompt::class, static::prefixedTable('keyword_prompt'));
    }
}
