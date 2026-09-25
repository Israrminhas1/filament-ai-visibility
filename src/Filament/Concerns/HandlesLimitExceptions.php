<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Concerns;

use Filament\Notifications\Notification;
use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;

/**
 * Turns a LimitExceeded thrown anywhere in a page or relation manager into a
 * notification instead of an error page (Livewire exception hook).
 */
trait HandlesLimitExceptions
{
    public function exception($e, $stopPropagation): void
    {
        if (! $e instanceof LimitExceeded) {
            return;
        }

        Notification::make()
            ->title('Limit reached')
            ->body($e->getMessage())
            ->warning()
            ->persistent()
            ->send();

        $stopPropagation();
    }
}
