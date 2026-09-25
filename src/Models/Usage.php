<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

/**
 * One AI call made by AI Visibility, for spend tracking and budgets.
 */
class Usage extends Model
{
    use BelongsToTenant;

    public const PURPOSE_TRACKING = 'tracking';

    public const PURPOSE_ANALYSIS = 'analysis';

    public const PURPOSE_CLASSIFICATION = 'classification';

    public const PURPOSE_GENERATION = 'generation';

    protected string $baseTable = 'usage';

    public const UPDATED_AT = null;

    protected $casts = [
        'cost_usd' => 'float',
    ];
}
