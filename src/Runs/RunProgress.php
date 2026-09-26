<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
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
     * Seconds after which a claim is treated as abandoned (the worker died mid-call).
     */
    public const CLAIM_EXPIRES_AFTER = 300;

    /**
     * Claim a pending result before asking the engine, so two workers never pay
     * for the same answer. Returns false when another worker has it or it is done.
     */
    public function claim(Result $result): bool
    {
        $claimed = Result::query()->withoutGlobalScopes()
            ->whereKey($result->getKey())
            ->where(fn ($query) => $query
                ->where('status', ResultStatus::Pending)
                ->orWhere(fn ($query) => $query
                    ->where('status', ResultStatus::Running)
                    ->where('updated_at', '<', now()->subSeconds(self::CLAIM_EXPIRES_AFTER))))
            ->update(['status' => ResultStatus::Running->value, 'updated_at' => now()]);

        if ($claimed) {
            $result->status = ResultStatus::Running;
        }

        return (bool) $claimed;
    }

    /**
     * Give a claimed result back (e.g. a temporary failure that will be retried).
     */
    public function release(Result $result, array $attributes = []): void
    {
        Result::query()->withoutGlobalScopes()
            ->whereKey($result->getKey())
            ->where('status', ResultStatus::Running)
            ->update([...$attributes, 'status' => ResultStatus::Pending->value, 'updated_at' => now()]);

        $result->status = ResultStatus::Pending;
    }

    /**
     * Mark a pending or claimed result as finished (success, failed or skipped) and update its run.
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
        $updated = Result::query()->withoutGlobalScopes()
            ->whereKey($result->getKey())
            ->whereIn('status', [ResultStatus::Pending->value, ResultStatus::Running->value])
            ->update([...$attributes, 'status' => $status->value, 'updated_at' => now()]);

        if (! $updated) {
            return;
        }

        $result->status = $status;

        $run = Run::query()->withoutGlobalScopes()->find($result->run_id);

        if (! $run) {
            return;
        }

        $cost = $status === ResultStatus::Success
            ? (float) Result::query()->withoutGlobalScopes()->whereKey($result->getKey())->value('cost_usd')
            : 0.0;

        Run::query()->withoutGlobalScopes()->whereKey($run->getKey())->update([
            $counter => DB::raw("{$counter} + 1"),
            'cost_usd' => DB::raw('cost_usd + ' . $cost),
            'updated_at' => now(),
        ]);

        $this->closeIfDone($run->fresh());
    }

    /**
     * Close the run once no result is waiting. The counters decide in the normal case;
     * when they fall short (results deleted with their prompt, lost jobs) the result
     * rows are counted instead, so a run can never stay "running" forever.
     */
    public function closeIfDone(Run $run): void
    {
        if ($run->finished_at !== null) {
            return;
        }

        if ($run->processed() < $run->results_total) {
            if ($this->open($run)->exists()) {
                return;
            }

            $run = $this->recount($run);
        }

        $status = match (true) {
            $run->results_total === 0 => RunStatus::Failed,
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

    /**
     * Set the run's counters from its result rows (rows deleted with their prompt drop out of the total).
     */
    public function recount(Run $run): Run
    {
        $counts = Result::query()->withoutGlobalScopes()
            ->where('run_id', $run->getKey())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        Run::query()->withoutGlobalScopes()->whereKey($run->getKey())->update([
            'results_total' => $counts->sum(),
            'results_done' => $counts[ResultStatus::Success->value] ?? 0,
            'results_failed' => $counts[ResultStatus::Failed->value] ?? 0,
            'results_skipped' => $counts[ResultStatus::Skipped->value] ?? 0,
            'updated_at' => now(),
        ]);

        return $run->fresh();
    }

    /**
     * Results of the run still waiting for an answer.
     */
    public function open(Run $run): Builder
    {
        return Result::query()->withoutGlobalScopes()
            ->where('run_id', $run->getKey())
            ->whereIn('status', [ResultStatus::Pending->value, ResultStatus::Running->value]);
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
