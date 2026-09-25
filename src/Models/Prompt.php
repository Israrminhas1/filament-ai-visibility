<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToBrand;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

class Prompt extends Model
{
    use BelongsToBrand;

    protected string $baseTable = 'prompts';

    protected $casts = [
        'tags' => 'array',
        'intent' => PromptIntent::class,
        'source' => PromptSource::class,
        'status' => PromptStatus::class,
        'last_run_at' => 'datetime',
    ];

    protected $attributes = [
        'intent' => 'discovery',
        'source' => 'manual',
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (Prompt $prompt) {
            $prompt->text = Text::squish($prompt->text);
            $prompt->text_hash = Text::hash($prompt->text);

            // Limits hold everywhere, not only in the UI.
            $activating = $prompt->status === PromptStatus::Active && ($prompt->isDirty('status') || ! $prompt->exists);

            if ($activating && $prompt->brand) {
                app(Limits::class)->ensureCanActivatePrompts($prompt->brand, 1, $prompt->getKey());
            }
        });
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, static::prefixedTable('keyword_prompt'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PromptStatus::Active);
    }
}
