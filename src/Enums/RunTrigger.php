<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RunTrigger: string implements HasColor, HasLabel
{
    case Schedule = 'schedule';

    case Manual = 'manual';

    case Retry = 'retry';

    public function getLabel(): string
    {
        return match ($this) {
            self::Schedule => 'Scheduled',
            self::Manual => 'Manual',
            self::Retry => 'Retry',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Schedule => 'gray',
            self::Manual => 'info',
            self::Retry => 'warning',
        };
    }
}
