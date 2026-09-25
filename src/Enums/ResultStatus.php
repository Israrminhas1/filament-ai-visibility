<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ResultStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    case Success = 'success';

    case Failed = 'failed';

    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }
}
