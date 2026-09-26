<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

class Health extends Page
{
    use HasAiVisibilityNavigation;

    protected static int $aiVisibilitySort = 95;

    protected string $view = 'ai-visibility::pages.health';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/health';
    }

    public static function getNavigationLabel(): string
    {
        return 'Health';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-heart';
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $paused = collect(app(EngineManager::class)->enabled())
                ->reject(fn (string $engine) => app(EngineManager::class)->isUsable($engine))
                ->count();
        } catch (\Throwable) {
            return null;
        }

        return $paused > 0 ? (string) $paused : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return 'AI Visibility health';
    }

    public function mount(): void
    {
        app(SystemHealth::class)->pingQueue();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label('Re-check')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => app(SystemHealth::class)->pingQueue()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $engines = app(EngineManager::class);
        $keys = app(KeyResolver::class);
        $enabled = $engines->enabled();

        $canManage = AiVisibilityPlugin::userCanManage();

        return [
            'canManage' => $canManage,
            'settingsUrl' => $canManage ? ManageSettings::enginesUrl() : null,
            'checks' => app(SystemHealth::class)->checks(),
            'killSwitch' => app(Settings::class)->killSwitch(),
            'engines' => collect($engines->registry()->all())->map(function ($engine, $key) use ($engines, $keys, $enabled) {
                $isEnabled = in_array($key, $enabled, true);
                $usable = $isEnabled && $engines->isUsable($key);
                $state = $engines->state($key);

                return [
                    'key' => $key,
                    'label' => $engine->label(),
                    'enabled' => $isEnabled,
                    'usable' => $usable,
                    'status' => $isEnabled ? $state->status : EngineStatus::Disabled,
                    'reason' => $state->reason,
                    'message' => $state->message,
                    'paused_at' => $state->paused_at,
                    'key_source' => $keys->source($key)['source'],
                    'model' => $engines->model($key),
                ];
            })->values()->all(),
        ];
    }

    public function testAndResume(string $engine): void
    {
        abort_unless(AiVisibilityPlugin::userCanManage(), 403);

        $result = app(EngineManager::class)->testAndResume($engine);

        Notification::make()
            ->title($result->ok ? 'Engine resumed' : 'Still paused: ' . $result->message)
            ->body($result->ok ? null : $result->reason?->fix())
            ->status($result->ok ? 'success' : 'danger')
            ->send();
    }

    public function pauseEngine(string $engine): void
    {
        abort_unless(AiVisibilityPlugin::userCanManage(), 403);

        app(EngineManager::class)->pause($engine, PauseReason::Manual);

        Notification::make()->title('Engine paused')->success()->send();
    }
}
