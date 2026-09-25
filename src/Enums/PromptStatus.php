<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PromptStatus: string implements HasColor, HasLabel
{
    case Suggested = 'suggested';

    case Active = 'active';

    case Paused = 'paused';

    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Suggested => 'info',
            self::Active => 'success',
            self::Paused => 'gray',
            self::Rejected => 'danger',
        };
    }
}
