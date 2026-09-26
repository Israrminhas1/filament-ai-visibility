<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Events\ResultRecorded;
use IsrarMinhas\FilamentAiVisibility\Jobs\Middleware\RateLimitEngine;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Runs\BudgetGuard;
use IsrarMinhas\FilamentAiVisibility\Runs\ResultRecorder;
use IsrarMinhas\FilamentAiVisibility\Runs\RunProgress;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use Throwable;

/**
 * Answers one prompt on one engine. Before calling anything it checks the
 * kill switch, budgets and the engine's state, so a paused engine costs
 * nothing and never gets retried in a loop.
 */
class RunResultJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Unexpected errors (not rate limits or outages) allowed before giving up.
     */
    public int $maxExceptions = 3;

    /**
     * Seconds one attempt may take. The queue connection's retry_after must be
     * longer, or a slow answer is handed to a second worker while the first runs.
     */
    public int $timeout = 240;

    /**
     * Seconds this job may keep retrying, counted from dispatch (see dispatchPaced()).
     */
    public ?int $retryFor = null;

    public function __construct(
        public readonly int $resultId,
        public readonly string $engine,
        public readonly int | string | null $tenantId,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.tracking', 'default'));
    }

    /**
     * Queue answers for one engine, spaced out at the engine's requests-per-minute.
     *
     * Every job used to get the same fixed retry window from dispatch, so in a big
     * run the last jobs spent their window waiting on the rate limiter and failed
     * before they were ever tried. Now job N waits for its own slot (N / rpm
     * minutes) and its window is that wait plus the configured window, with the wait counted twice
     * as a margin for other runs sharing the limit: at least the configured
     * window (1 hour by default), at most 24 hours.
     *
     * @param  iterable<int>  $resultIds
     */
    public static function dispatchPaced(iterable $resultIds, string $engine, int | string | null $tenantId): int
    {
        $rpm = max(1, Tenancy::as($tenantId, fn () => app(EngineManager::class)->requestsPerMinute($engine)));
        $window = max(3600, (int) config('ai-visibility.tracking.retry_for_seconds', 3600));
        $queued = 0;

        foreach ($resultIds as $resultId) {
            $wait = intdiv($queued, $rpm) * 60;
            $job = (new static((int) $resultId, $engine, $tenantId))->retryFor(min(86400, $window + $wait * 2));

            if ($wait > 0) {
                $job->delay($wait);
            }

            dispatch($job);
            $queued++;
        }

        return $queued;
    }

    public function retryFor(int $seconds): static
    {
        $this->retryFor = $seconds;

        return $this;
    }

    public function middleware(): array
    {
        return [new RateLimitEngine];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->retryFor ?? (int) config('ai-visibility.tracking.retry_for_seconds', 3600));
    }

    public function handle(): void
    {
        Tenancy::as($this->tenantId, fn () => $this->process());
    }

    protected function process(): void
    {
        $result = Result::query()->with(['brand', 'prompt'])->find($this->resultId);
        $progress = app(RunProgress::class);

        // Deleted with its run or prompt: nothing left to answer or count.
        if (! $result) {
            return;
        }

        // Claim it first: a duplicate job (retry, re-dispatch) must never pay for the same answer.
        if (! $progress->claim($result)) {
            return;
        }

        if (! $result->brand || ! $result->prompt) {
            $progress->finish($result, ResultStatus::Failed, ['error' => 'The prompt or brand was deleted before it could be asked.']);

            return;
        }

        $engines = app(EngineManager::class);
        $budget = app(BudgetGuard::class);

        if (app(Settings::class)->killSwitch()) {
            $progress->finish($result, ResultStatus::Skipped, ['skip_reason' => 'kill_switch']);

            return;
        }

        if ($budget->blocks($result->brand)) {
            $result->run()->update(['status_reason' => 'budget']);
            $progress->finish($result, ResultStatus::Skipped, ['skip_reason' => 'budget']);

            return;
        }

        if (! $engines->isUsable($this->engine)) {
            $progress->finish($result, ResultStatus::Skipped, ['skip_reason' => 'engine_paused:' . $engines->state($this->engine)->reason?->value]);

            return;
        }

        $result->increment('attempts');
        $started = hrtime(true);

        try {
            $response = app(EngineRegistry::class)->get($this->engine)->ask(new EngineRequest(
                prompt: $result->prompt->text,
                model: $result->model ?: $engines->model($this->engine, $result->brand),
                apiKey: (string) app(KeyResolver::class)->resolve($this->engine),
                country: $result->brand->countryCode(),
            ));
        } catch (EngineRequestFailed $e) {
            $this->handleFailure($result, $e);

            return;
        } catch (Throwable $e) {
            // Unexpected error: hand the result back so the retry can claim it.
            $progress->release($result, ['error' => str($e->getMessage())->limit(500)->toString()]);

            throw $e;
        }

        if (trim($response->answer) === '') {
            $engines->recordFailure($this->engine, null, 'Empty answer');
            $progress->finish($result, ResultStatus::Failed, ['error' => 'The engine returned an empty answer.']);

            return;
        }

        try {
            app(ResultRecorder::class)->record($result, $response, (int) ((hrtime(true) - $started) / 1_000_000));
        } catch (Throwable $e) {
            $progress->release($result, ['error' => str($e->getMessage())->limit(500)->toString()]);

            throw $e;
        }

        $engines->recordSuccess($this->engine);
        $progress->finish($result, ResultStatus::Success);

        ResultRecorded::dispatch($result->fresh());

        $budget->enforce($result->brand);
    }

    protected function handleFailure(Result $result, EngineRequestFailed $e): void
    {
        $engines = app(EngineManager::class);
        $engines->recordFailure($this->engine, $e->reason, $e->getMessage(), $e->retryAfter);

        $state = $engines->state($this->engine);

        // The engine is now paused: skip instead of retrying against it.
        if ($state->status === EngineStatus::Paused) {
            app(RunProgress::class)->finish($result, ResultStatus::Skipped, [
                'skip_reason' => 'engine_paused:' . $state->reason?->value,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Temporary problems: give the claim back and try again later, within retryUntil().
        if ($e->isTransient()) {
            app(RunProgress::class)->release($result, ['error' => $e->getMessage()]);
            $this->release($e->reason === PauseReason::RateLimited ? max(30, $e->retryAfter ?? 60) : $this->backoffSeconds());

            return;
        }

        // A problem with this one request (e.g. rejected content): no point retrying.
        app(RunProgress::class)->finish($result, ResultStatus::Failed, ['error' => $e->getMessage()]);
    }

    protected function backoffSeconds(): int
    {
        return [30, 120, 300, 600][min($this->attempts() - 1, 3)] ?? 600;
    }

    /**
     * Retries ran out or an unexpected error kept happening.
     */
    public function failed(?Throwable $exception): void
    {
        Tenancy::as($this->tenantId, function () use ($exception) {
            $result = Result::query()->find($this->resultId);

            if ($result && in_array($result->status, [ResultStatus::Pending, ResultStatus::Running], true)) {
                app(RunProgress::class)->finish($result, ResultStatus::Failed, [
                    'error' => $exception ? str($exception->getMessage())->limit(500)->toString() : 'The job failed.',
                ]);
            }
        });
    }
}
