<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToBrand;

class Topic extends Model
{
    use BelongsToBrand;

    protected string $baseTable = 'topics';

    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }
}
