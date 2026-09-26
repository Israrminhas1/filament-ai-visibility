<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ResultStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    // Claimed by a worker that is asking the engine right now.
    case Running = 'running';

    case Success = 'success';

    case Failed = 'failed';

    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }
}
