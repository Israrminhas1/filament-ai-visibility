<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EngineStatus: string implements HasColor, HasLabel
{
    case Active = 'active';

    case Degraded = 'degraded';

    case Paused = 'paused';

    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Degraded => 'Degraded',
            self::Paused => 'Paused',
            self::Disabled => 'Disabled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Degraded => 'warning',
            self::Paused => 'danger',
            self::Disabled => 'gray',
        };
    }
}
