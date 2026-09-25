<?php

namespace IsrarMinhas\FilamentAiVisibility\Enums;

use Filament\Support\Contracts\HasLabel;

enum PauseReason: string implements HasLabel
{
    case MissingKey = 'missing_key';

    case InvalidKey = 'invalid_key';

    case InsufficientCredits = 'insufficient_credits';

    case RateLimited = 'rate_limited';

    case ModelUnavailable = 'model_unavailable';

    case ProviderOutage = 'provider_outage';

    case Budget = 'budget';

    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::MissingKey => 'No API key',
            self::InvalidKey => 'Invalid API key',
            self::InsufficientCredits => 'Out of credits',
            self::RateLimited => 'Rate limited',
            self::ModelUnavailable => 'Model unavailable',
            self::ProviderOutage => 'Provider outage',
            self::Budget => 'Budget reached',
            self::Manual => 'Paused manually',
        };
    }

    /**
     * What the user should do about it.
     */
    public function fix(): string
    {
        return match ($this) {
            self::MissingKey => 'Add an API key for this engine, then click "Test & resume".',
            self::InvalidKey => 'The provider rejected the API key. Replace it, then click "Test & resume".',
            self::InsufficientCredits => 'Add credits or a payment method in the provider\'s billing dashboard. The engine is re-checked automatically every few hours, or click "Test & resume".',
            self::RateLimited => 'The provider is limiting requests. The engine resumes automatically; lower "Requests per minute" in Settings if this keeps happening.',
            self::ModelUnavailable => 'The selected model is not available for this key. Choose another model in Settings, then click "Test & resume".',
            self::ProviderOutage => 'The provider is failing or unreachable. The engine is retried automatically.',
            self::Budget => 'The monthly budget was reached. Raise the budget in Settings or wait for the next month.',
            self::Manual => 'Resume the engine when you are ready.',
        };
    }

    /**
     * Whether the engine recovers on its own (automatic probes/backoff).
     */
    public function resumesAutomatically(): bool
    {
        return in_array($this, [self::InsufficientCredits, self::RateLimited, self::ProviderOutage], true);
    }
}
