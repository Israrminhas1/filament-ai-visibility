<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Sentiment: string implements HasColor, HasLabel
{
    case Positive = 'positive';

    case Neutral = 'neutral';

    case Negative = 'negative';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Positive => 'success',
            self::Neutral => 'gray',
            self::Negative => 'danger',
        };
    }

    public function hex(): string
    {
        return match ($this) {
            self::Positive => '#10b981',
            self::Neutral => '#9ca3af',
            self::Negative => '#ef4444',
        };
    }
}
