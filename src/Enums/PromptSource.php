<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PromptSource: string implements HasColor, HasLabel
{
    case Manual = 'manual';

    case Generated = 'generated';

    case Imported = 'imported';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Generated => 'Generated',
            self::Imported => 'Imported',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Generated => 'info',
            self::Imported => 'warning',
        };
    }
}
