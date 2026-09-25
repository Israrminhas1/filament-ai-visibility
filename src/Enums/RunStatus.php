<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RunStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    case Running = 'running';

    case Completed = 'completed';

    case Partial = 'partial';

    case Failed = 'failed';

    case StoppedBudget = 'stopped_budget';

    case StoppedPaused = 'stopped_paused';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Partial => 'Partly completed',
            self::Failed => 'Failed',
            self::StoppedBudget => 'Stopped (budget)',
            self::StoppedPaused => 'Stopped (engines paused)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Completed => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
            self::StoppedBudget => 'danger',
            self::StoppedPaused => 'danger',
        };
    }
}
