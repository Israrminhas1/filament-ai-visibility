<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RunFrequency: string implements HasColor, HasLabel
{
    case Daily = 'daily';

    case Weekly = 'weekly';

    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Manual => 'Manual only',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Daily => 'success',
            self::Weekly => 'info',
            self::Manual => 'gray',
        };
    }
}
