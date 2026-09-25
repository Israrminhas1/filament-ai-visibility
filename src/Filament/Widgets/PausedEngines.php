<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

/**
 * A banner shown only while something stops tracking: paused engines or "Pause everything".
 */
class PausedEngines extends Widget
{
    protected string $view = 'ai-visibility::widgets.paused-engines';

    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::issues() !== [];
    }

    /**
     * @return array<string>
     */
    public static function issues(): array
    {
        $engines = app(EngineManager::class);
        $issues = [];

        if (app(Settings::class)->killSwitch()) {
            $issues[] = '"Pause everything" is on in Settings, so nothing is being tracked.';
        }

        foreach ($engines->enabled() as $engine) {
            if (! $engines->isUsable($engine)) {
                $state = $engines->state($engine);
                $issues[] = $engines->registry()->get($engine)->label() . ' is paused: ' . ($state->reason?->getLabel() ?? 'unknown reason') . '. ' . ($state->reason?->fix() ?? '');
            }
        }

        return $issues;
    }

    protected function getViewData(): array
    {
        return [
            'issues' => static::issues(),
            'healthUrl' => AiVisibilityPlugin::pageUrl(Health::class),
        ];
    }
}
