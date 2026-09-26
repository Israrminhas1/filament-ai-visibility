<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
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
     * Each issue with a link to where it is fixed: Settings for key problems, Health otherwise.
     *
     * @return array<int, array{text: string, url: ?string, label: ?string}>
     */
    public static function issues(): array
    {
        $engines = app(EngineManager::class);
        $issues = [];

        if (app(Settings::class)->killSwitch()) {
            $issues[] = static::issue('"Pause everything" is on in Settings, so nothing is being tracked.', AiVisibilityPlugin::pageUrl(ManageSettings::class));
        }

        foreach ($engines->enabled() as $engine) {
            if (! $engines->isUsable($engine)) {
                $reason = $engines->state($engine)->reason;

                $issues[] = static::issue(
                    $engines->registry()->get($engine)->label() . ' is paused: ' . ($reason?->getLabel() ?? 'unknown reason') . '. ' . ($reason?->fix() ?? ''),
                    in_array($reason, [PauseReason::MissingKey, PauseReason::InvalidKey, PauseReason::InsufficientCredits], true) ? ManageSettings::enginesUrl() : null,
                );
            }
        }

        return $issues;
    }

    /**
     * @return array{text: string, url: ?string, label: ?string}
     */
    protected static function issue(string $text, ?string $settingsUrl): array
    {
        if ($settingsUrl && AiVisibilityPlugin::userCanManage()) {
            return ['text' => $text, 'url' => $settingsUrl, 'label' => 'Open settings'];
        }

        $url = AiVisibilityPlugin::pageUrl(Health::class);

        return ['text' => $text, 'url' => $url, 'label' => $url ? 'Open health' : null];
    }

    protected function getViewData(): array
    {
        return [
            'issues' => static::issues(),
            'healthUrl' => AiVisibilityPlugin::pageUrl(Health::class),
        ];
    }
}
