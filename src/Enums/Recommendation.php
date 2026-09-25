<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How strongly an answer recommends a brand, strongest first.
 */
enum Recommendation: string implements HasColor, HasDescription, HasLabel
{
    case TopPick = 'top_pick';

    case Recommended = 'recommended';

    case Listed = 'listed';

    case Passing = 'passing';

    case Cautioned = 'cautioned';

    public function getLabel(): string
    {
        return match ($this) {
            self::TopPick => 'Top pick',
            self::Recommended => 'Recommended',
            self::Listed => 'Listed',
            self::Passing => 'In passing',
            self::Cautioned => 'Cautioned against',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::TopPick => 'Named as the best or first choice.',
            self::Recommended => 'Recommended, but not as the single best choice.',
            self::Listed => 'One option in a list, without a recommendation.',
            self::Passing => 'Mentioned in passing, not as an option.',
            self::Cautioned => 'The answer warns against it or points out problems.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::TopPick => 'success',
            self::Recommended => 'info',
            self::Listed => 'gray',
            self::Passing => 'gray',
            self::Cautioned => 'danger',
        };
    }

    public function hex(): string
    {
        return match ($this) {
            self::TopPick => '#059669',
            self::Recommended => '#3b82f6',
            self::Listed => '#9ca3af',
            self::Passing => '#d1d5db',
            self::Cautioned => '#ef4444',
        };
    }
}
