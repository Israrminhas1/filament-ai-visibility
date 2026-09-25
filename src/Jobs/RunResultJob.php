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

    public function __construct(
        public readonly int $resultId,
        public readonly string $engine,
        public readonly int | string | null $tenantId,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.tracking', 'default'));
    }

    public function middleware(): array
    {
        return [new RateLimitEngine];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds((int) config('ai-visibility.tracking.retry_for_seconds', 3600));
    }

    public function handle(): void
    {
        Tenancy::as($this->tenantId, fn () => $this->process());
    }

    protected function process(): void
    {
        $result = Result::query()->with(['brand', 'prompt'])->find($this->resultId);

        if (! $result || $result->status !== ResultStatus::Pending || ! $result->brand || ! $result->prompt) {
            return;
        }

        $engines = app(EngineManager::class);
        $progress = app(RunProgress::class);
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
        }

        if (trim($response->answer) === '') {
            $engines->recordFailure($this->engine, null, 'Empty answer');
            $progress->finish($result, ResultStatus::Failed, ['error' => 'The engine returned an empty answer.']);

            return;
        }

        app(ResultRecorder::class)->record($result, $response, (int) ((hrtime(true) - $started) / 1_000_000));
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

        // Temporary problems: try again later, within retryUntil().
        if ($e->isTransient()) {
            $result->forceFill(['error' => $e->getMessage()])->save();
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

            if ($result && $result->status === ResultStatus::Pending) {
                app(RunProgress::class)->finish($result, ResultStatus::Failed, [
                    'error' => $exception ? str($exception->getMessage())->limit(500)->toString() : 'The job failed.',
                ]);
            }
        });
    }
}
