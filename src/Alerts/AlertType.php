<?php

namespace IsrarMinhas\FilamentAiVisibility\Alerts;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;

enum AlertType: string implements HasDescription, HasLabel
{
    case VisibilityDrop = 'visibility_drop';

    case CompetitorOvertakes = 'competitor_overtakes';

    case NewCompetitor = 'new_competitor';

    case PromptLost = 'prompt_lost';

    case NegativeSentiment = 'negative_sentiment';

    case BudgetThreshold = 'budget_threshold';

    case RunFailed = 'run_failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::VisibilityDrop => 'Visibility drops',
            self::CompetitorOvertakes => 'A competitor overtakes you',
            self::NewCompetitor => 'New direct competitor found',
            self::PromptLost => 'A prompt stops mentioning you',
            self::NegativeSentiment => 'Negative mentions increase',
            self::BudgetThreshold => 'Spend reaches part of the budget',
            self::RunFailed => 'A run fails',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::VisibilityDrop => 'Visibility over the last N days falls by more than X points vs the N days before.',
            self::CompetitorOvertakes => 'A competitor is mentioned in more answers than you over the last N days.',
            self::NewCompetitor => 'A discovered candidate is classified as a direct competitor.',
            self::PromptLost => 'You were mentioned in the last few runs of a prompt on an engine, then in none of the answers in the latest run.',
            self::NegativeSentiment => 'The share of negative mentions of your brand over the last N days exceeds X%.',
            self::BudgetThreshold => 'This month\'s spend passes X% of the monthly budget (once per month).',
            self::RunFailed => 'A run fails, or more than X% of its answers fail or are skipped.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::VisibilityDrop => ['days' => 7, 'points' => 10, 'engine' => null],
            self::CompetitorOvertakes => ['days' => 7, 'competitor_id' => null],
            self::NewCompetitor => [],
            self::PromptLost => ['previous' => 2],
            self::NegativeSentiment => ['days' => 7, 'percent' => 20],
            self::BudgetThreshold => ['percent' => 80],
            self::RunFailed => ['percent' => 50],
        };
    }

    /**
     * Whether the rule applies to one brand (vs the whole account).
     */
    public function isBrandLevel(): bool
    {
        return $this !== self::BudgetThreshold;
    }

    /**
     * @return array<\Filament\Forms\Components\Field>
     */
    public function fields(): array
    {
        return match ($this) {
            self::VisibilityDrop => [
                TextInput::make('config.days')->label('Compare the last (days)')->numeric()->minValue(1)->default(7),
                TextInput::make('config.points')->label('Alert when it drops by at least (points)')->numeric()->minValue(1)->default(10),
                Select::make('config.engine')->label('Engine')->placeholder('All engines')->options(fn () => app(EngineRegistry::class)->options()),
            ],
            self::CompetitorOvertakes => [
                TextInput::make('config.days')->label('Over the last (days)')->numeric()->minValue(1)->default(7),
                Select::make('config.competitor_id')
                    ->label('Competitor')
                    ->placeholder('Any competitor')
                    ->options(fn (Get $get) => Competitor::query()->where('brand_id', $get('brand_id'))->pluck('name', 'id')),
            ],
            self::PromptLost => [
                TextInput::make('config.previous')->label('Mentioned in at least this many runs before')->numeric()->minValue(1)->default(2),
            ],
            self::NegativeSentiment => [
                TextInput::make('config.days')->label('Over the last (days)')->numeric()->minValue(1)->default(7),
                TextInput::make('config.percent')->label('Alert above (% negative)')->numeric()->minValue(1)->maxValue(100)->default(20),
            ],
            self::BudgetThreshold => [
                TextInput::make('config.percent')->label('Alert at (% of the monthly budget)')->numeric()->minValue(1)->maxValue(100)->default(80),
            ],
            self::RunFailed => [
                TextInput::make('config.percent')->label('Also alert when at least (%) of answers fail or are skipped')->numeric()->minValue(1)->maxValue(100)->default(50),
            ],
            default => [],
        };
    }
}
