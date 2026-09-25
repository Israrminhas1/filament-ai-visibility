<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Events\RunCompleted;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use Throwable;

/**
 * Updates run counters as results finish and closes the run after the last one.
 */
class RunProgress
{
    /**
     * Mark a result as finished (success, failed or skipped) and update its run.
     */
    public function finish(Result $result, ResultStatus $status, array $attributes = []): void
    {
        $counter = match ($status) {
            ResultStatus::Success => 'results_done',
            ResultStatus::Failed => 'results_failed',
            ResultStatus::Skipped => 'results_skipped',
            default => null,
        };

        if ($counter === null) {
            return;
        }

        // Only count a result once, even if a job is retried.
        $updated = Result::query()
            ->whereKey($result->getKey())
            ->where('status', ResultStatus::Pending)
            ->update([...$attributes, 'status' => $status->value, 'updated_at' => now()]);

        if (! $updated) {
            return;
        }

        $run = Run::query()->withoutGlobalScopes()->find($result->run_id);

        if (! $run) {
            return;
        }

        Run::query()->withoutGlobalScopes()->whereKey($run->getKey())->update([
            $counter => DB::raw("{$counter} + 1"),
            'cost_usd' => DB::raw('cost_usd + ' . (float) ($status === ResultStatus::Success ? $result->fresh()->cost_usd : 0)),
            'updated_at' => now(),
        ]);

        $this->closeIfDone($run->fresh());
    }

    public function closeIfDone(Run $run): void
    {
        if ($run->finished_at !== null || $run->processed() < $run->results_total) {
            return;
        }

        $status = match (true) {
            $run->results_done === $run->results_total => RunStatus::Completed,
            $run->results_done === 0 && $run->results_skipped > 0 && $run->status_reason === 'budget' => RunStatus::StoppedBudget,
            $run->results_done === 0 && $run->results_skipped > 0 => RunStatus::StoppedPaused,
            $run->results_done === 0 => RunStatus::Failed,
            default => RunStatus::Partial,
        };

        // Close the run exactly once, even with several workers finishing together.
        $closed = Run::query()->withoutGlobalScopes()
            ->whereKey($run->getKey())
            ->whereNull('finished_at')
            ->update(['status' => $status->value, 'finished_at' => now(), 'updated_at' => now()]);

        if (! $closed) {
            return;
        }

        $run = $run->fresh();

        RunCompleted::dispatch($run);

        $this->notifyStarter($run);
    }

    protected function notifyStarter(Run $run): void
    {
        $userModel = config('auth.providers.users.model');

        if (! $run->triggered_by || ! $userModel || ! class_exists($userModel) || ! ($user = $userModel::query()->find($run->triggered_by))) {
            return;
        }

        try {
            $visible = $run->results()->where('status', ResultStatus::Success)->where('brand_mentioned', true)->count();
            $done = max(1, $run->results_done);

            $notification = Notification::make()
                ->title("Run for {$run->brand?->name}: {$run->status->getLabel()}")
                ->body("{$run->results_done} answers collected, brand mentioned in " . round($visible / $done * 100) . '%.' . ($run->results_skipped ? " {$run->results_skipped} skipped." : ''))
                ->status($run->status === RunStatus::Completed ? 'success' : 'warning');

            if ($url = AiVisibilityPlugin::pageUrl(RunResource::class, 'view', ['record' => $run])) {
                $notification->actions([\Filament\Actions\Action::make('view')->url($url)->button()]);
            }

            $user->notifyNow($notification->toDatabase());
        } catch (Throwable) {
            //
        }
    }
}
