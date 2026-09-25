<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PromptIntent: string implements HasColor, HasLabel
{
    case Discovery = 'discovery';

    case Comparison = 'comparison';

    case Alternatives = 'alternatives';

    case Problem = 'problem';

    case Branded = 'branded';

    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Discovery => 'Discovery',
            self::Comparison => 'Comparison',
            self::Alternatives => 'Alternatives',
            self::Problem => 'Problem solving',
            self::Branded => 'Branded',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Discovery => 'info',
            self::Comparison => 'warning',
            self::Alternatives => 'primary',
            self::Problem => 'success',
            self::Branded => 'danger',
            self::Other => 'gray',
        };
    }
}
