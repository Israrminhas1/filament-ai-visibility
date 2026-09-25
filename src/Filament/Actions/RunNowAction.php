<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Exceptions\RunNotStarted;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Support\CostEstimator;

class RunNowAction
{
    /**
     * @param  callable(): ?Brand|null  $brand  The brand to run; defaults to the action's record.
     */
    public static function make(?callable $brand = null): Action
    {
        return Action::make('runNow')
            ->label('Run now')
            ->icon('heroicon-o-play')
            ->requiresConfirmation()
            ->modalHeading(fn (?Brand $record) => 'Run ' . (($brand ? $brand() : $record)?->name ?? 'brand') . ' now?')
            ->modalDescription(function (?Brand $record) use ($brand) {
                $target = $brand ? $brand() : $record;

                if (! $target) {
                    return null;
                }

                $engines = app(EngineManager::class)->usable($target);
                $prompts = $target->activePrompts()->count();
                $samples = (int) $target->setting('runs.samples', 1);

                return sprintf(
                    'Asks %d active prompts on %d engine(s) × %d sample(s) = %d answers, costing about %s.',
                    $prompts,
                    count($engines),
                    $samples,
                    $prompts * count($engines) * $samples,
                    CostEstimator::format(CostEstimator::costPerRun($prompts, $engines, $samples)),
                );
            })
            ->modalSubmitActionLabel('Start run')
            ->action(function (?Brand $record) use ($brand) {
                $target = $brand ? $brand() : $record;

                try {
                    $run = app(RunPlanner::class)->start($target, RunTrigger::Manual, userId: auth()->id());
                } catch (RunNotStarted $e) {
                    Notification::make()->title('Run not started')->body($e->getMessage())->warning()->persistent()->send();

                    return;
                }

                $notification = Notification::make()
                    ->title('Run started')
                    ->body("Collecting {$run->results_total} answers. You'll be notified when it finishes.")
                    ->success();

                if ($url = AiVisibilityPlugin::pageUrl(RunResource::class, 'view', ['record' => $run])) {
                    $notification->actions([Action::make('view')->label('View run')->url($url)->button()]);
                }

                $notification->send();
            });
    }
}
