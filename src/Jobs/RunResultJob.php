<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
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
     * Unix time of this job's slot on the engine's shared schedule (see dispatchPaced()).
     * A job picked up earlier waits for it.
     */
    public ?int $availableAt = null;

    /**
     * Unix time after which this job stops retrying.
     */
    public ?int $retryUntilAt = null;

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
     * The rate limit is shared by every run of the tenant on that engine, so the
     * spacing is too: each call reserves the next free slots on one schedule per
     * tenant and engine (several brands starting in the same minute queue behind
     * each other instead of all starting at once). Each job may retry for the
     * configured window counted from its own slot, so waiting for the slot never
     * uses up its retries, however big the backlog.
     *
     * @param  iterable<int>  $resultIds
     */
    public static function dispatchPaced(iterable $resultIds, string $engine, int | string | null $tenantId): int
    {
        $ids = collect($resultIds)->map(fn ($id) => (int) $id)->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $rpm = max(1, Tenancy::as($tenantId, fn () => app(EngineManager::class)->requestsPerMinute($engine)));
        $window = max(3600, (int) config('ai-visibility.tracking.retry_for_seconds', 3600));
        $interval = 60000 / $rpm;
        $first = static::reserveSlots($tenantId, $engine, $ids->count(), $interval);
        $now = now()->getTimestamp();

        foreach ($ids as $i => $resultId) {
            $slot = intdiv((int) ($first + $i * $interval), 1000);
            $job = (new static($resultId, $engine, $tenantId))->pace($slot, $slot + $window);

            // Long waits are split up: the job releases itself until its slot (see RateLimitEngine).
            if ($slot > $now) {
                $job->delay(min($slot - $now, static::maxDelay()));
            }

            dispatch($job);
        }

        return $ids->count();
    }

    /**
     * Reserve consecutive slots on the tenant and engine's schedule.
     *
     * @return float The first slot, in milliseconds.
     */
    protected static function reserveSlots(int | string | null $tenantId, string $engine, int $count, float $interval): float
    {
        $key = static::scheduleKey($tenantId, $engine);

        return Cache::lock("{$key}:lock", 10)->block(10, function () use ($key, $count, $interval) {
            $now = (float) now()->getPreciseTimestamp(3);
            $first = max($now, (float) Cache::get($key, 0));
            $next = $first + $count * $interval;

            Cache::put($key, $next, now()->addSeconds((int) ceil(($next - $now) / 1000) + 3600));

            return $first;
        });
    }

    /**
     * Unix time until which the tenant and engine's schedule is taken, or null when it is free.
     */
    public static function scheduledUntil(int | string | null $tenantId, string $engine): ?int
    {
        $next = (int) floor((float) Cache::get(static::scheduleKey($tenantId, $engine), 0) / 1000);

        return $next > now()->getTimestamp() ? $next : null;
    }

    protected static function scheduleKey(int | string | null $tenantId, string $engine): string
    {
        return "ai-visibility:schedule:{$tenantId}:{$engine}";
    }

    /**
     * Longest queue delay used at once (SQS allows at most 15 minutes).
     */
    public static function maxDelay(): int
    {
        return max(1, (int) config('ai-visibility.tracking.max_queue_delay', 900));
    }

    public function pace(int $availableAt, int $retryUntil): static
    {
        $this->availableAt = $availableAt;
        $this->retryUntilAt = max($retryUntil, $availableAt + 60);

        return $this;
    }

    /**
     * A claim outlives the job's timeout, so it is only taken over from a worker that really died.
     */
    public function claimExpiresAfter(): int
    {
        return $this->timeout + 60;
    }

    public function middleware(): array
    {
        return [new RateLimitEngine];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryUntilAt !== null
            ? now()->setTimestamp($this->retryUntilAt)
            : now()->addSeconds((int) config('ai-visibility.tracking.retry_for_seconds', 3600));
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
        if (! $progress->claim($result, $this->claimExpiresAfter())) {
            // Another worker is answering it. Come back when its claim would expire, in case that
            // worker died: the answer is then asked again instead of being lost.
            if ($left = $progress->claimHeldFor($result, $this->claimExpiresAfter())) {
                $this->release(min($left + 1, static::maxDelay()));
            }

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

            if (! $result || ! in_array($result->status, [ResultStatus::Pending, ResultStatus::Running], true)) {
                return;
            }

            $progress = app(RunProgress::class);

            // A duplicate of this job is still answering it: leave it to that worker.
            if ($progress->claimHeldFor($result, $this->claimExpiresAfter())) {
                return;
            }

            $progress->finish($result, ResultStatus::Failed, [
                'error' => match (true) {
                    $exception instanceof MaxAttemptsExceededException => RunProgress::RETRY_WINDOW_EXPIRED,
                    $exception !== null => str($exception->getMessage())->limit(500)->toString(),
                    default => 'The job failed.',
                },
            ]);
        });
    }
}
