<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\SupportsBatches;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Events\ResultRecorded;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Models\Batch;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

/**
 * Economy mode: scheduled runs go through the providers' batch APIs, which
 * cost about half as much but can take up to a day. Anything a batch cannot
 * answer (failed or expired batches, rejected items) is retried in real time,
 * so a run always finishes.
 */
class Economy
{
    public const CUSTOM_ID_PREFIX = 'result-';

    public function __construct(
        protected Settings $settings,
        protected EngineRegistry $registry,
        protected EngineManager $engines,
        protected KeyResolver $keys,
        protected RunProgress $progress,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('engines.economy', false);
    }

    /**
     * Whether this run's answers from this engine should be batched.
     */
    public function applies(Run $run, string $engine): bool
    {
        return $this->enabled()
            && $run->trigger === RunTrigger::Schedule
            && $this->supports($engine);
    }

    public function supports(string $engine): bool
    {
        return $this->registry->has($engine) && $this->registry->get($engine) instanceof SupportsBatches;
    }

    /**
     * Send pending results to the engine's batch API.
     *
     * @param  array<int>  $resultIds
     */
    public function submit(Run $run, string $engine, array $resultIds): ?Batch
    {
        $results = $this->pending($resultIds, $engine);

        if ($results->isEmpty()) {
            return null;
        }

        if (! $this->supports($engine)) {
            $this->realtime($results->modelKeys(), $engine, $run->tenant_id);

            return null;
        }

        if ($this->settings->killSwitch()) {
            $this->skip($results, 'kill_switch');

            return null;
        }

        if (app(BudgetGuard::class)->blocks($results->first()->brand)) {
            $run->update(['status_reason' => 'budget']);
            $this->skip($results, 'budget');

            return null;
        }

        if (! $this->engines->isUsable($engine)) {
            $this->skip($results, 'engine_paused:' . $this->engines->state($engine)->reason?->value);

            return null;
        }

        $requests = [];

        foreach ($results as $result) {
            $requests[self::CUSTOM_ID_PREFIX . $result->getKey()] = new EngineRequest(
                prompt: $result->prompt->text,
                model: $result->model ?: $this->engines->model($engine, $result->brand),
                apiKey: (string) $this->keys->resolve($engine),
                country: $result->brand->countryCode(),
            );
        }

        /** @var SupportsBatches $driver */
        $driver = $this->registry->get($engine);

        try {
            $providerId = $driver->submitBatch((string) $this->keys->resolve($engine), $requests);
        } catch (EngineRequestFailed $e) {
            // Rejected by the batch API only: real-time requests decide whether the model really can't be used.
            if ($e->reason === PauseReason::ModelUnavailable) {
                $this->realtime($results->modelKeys(), $engine, $run->tenant_id);

                return null;
            }

            $this->engines->recordFailure($engine, $e->reason, $e->getMessage(), $e->retryAfter);

            if ($this->engines->state($engine)->status === EngineStatus::Paused) {
                $this->skip($results, 'engine_paused:' . $this->engines->state($engine)->reason?->value, $e->getMessage());
            } else {
                $this->realtime($results->modelKeys(), $engine, $run->tenant_id);
            }

            return null;
        }

        Result::query()->whereKey($results->modelKeys())->increment('attempts');

        return Batch::query()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->getKey(),
            'engine' => $engine,
            'provider_batch_id' => $providerId,
            'status' => Batch::SUBMITTED,
            'result_ids' => $results->modelKeys(),
            'submitted_at' => now(),
        ]);
    }

    /**
     * Check a submitted batch and store its answers once it has finished.
     *
     * @return string What happened, for the command output.
     */
    public function poll(Batch $batch): string
    {
        $apiKey = $this->keys->resolve($batch->engine);
        $overdue = $batch->submitted_at?->lt(now()->subHours((int) config('ai-visibility.economy.give_up_after_hours', 26))) ?? true;

        if (! $this->supports($batch->engine) || blank($apiKey)) {
            if ($overdue) {
                $this->close($batch, Batch::FAILED, 'The engine or its API key is no longer available.');
            } else {
                $batch->forceFill(['checked_at' => now()])->save();
            }

            return 'waiting (no key)';
        }

        /** @var SupportsBatches $driver */
        $driver = $this->registry->get($batch->engine);

        try {
            $status = $driver->batchStatus($apiKey, $batch->provider_batch_id);
        } catch (EngineRequestFailed $e) {
            $this->recordPollFailure($batch->engine, $e);

            if ($overdue) {
                $this->close($batch, Batch::FAILED, $e->getMessage());
            }

            return 'error: ' . $e->getMessage();
        }

        if ($status->isFailed()) {
            $this->close($batch, Batch::FAILED, $status->message);

            return 'failed, retrying in real time';
        }

        if (! $status->isDone()) {
            if ($overdue) {
                $this->close($batch, Batch::FAILED, 'The batch did not finish in time.');

                return 'overdue, retrying in real time';
            }

            $batch->forceFill(['checked_at' => now()])->save();

            return 'in progress';
        }

        try {
            $stored = $this->collect($batch, $driver->batchResults($apiKey, $batch->provider_batch_id));
        } catch (EngineRequestFailed $e) {
            // Downloading failed; answers already stored stay stored and the rest are fetched next time.
            $this->recordPollFailure($batch->engine, $e);

            if ($overdue) {
                $this->close($batch, Batch::FAILED, $e->getMessage());
            }

            return 'error: ' . $e->getMessage();
        }

        $this->close($batch, Batch::COMPLETED);

        return "completed ({$stored} answers)";
    }

    /**
     * @param  iterable<string, EngineResponse|EngineRequestFailed>  $outcomes
     */
    protected function collect(Batch $batch, iterable $outcomes): int
    {
        $ids = array_flip(array_map('intval', $batch->result_ids ?? []));
        $retry = [];
        $brands = [];
        $stored = 0;

        foreach ($outcomes as $customId => $outcome) {
            $id = (int) substr((string) $customId, strlen(self::CUSTOM_ID_PREFIX));

            if (! isset($ids[$id])) {
                continue;
            }

            $result = Result::query()->with(['brand', 'prompt'])->find($id);

            if (! $result || $result->status !== ResultStatus::Pending || ! $result->brand) {
                continue;
            }

            if ($outcome instanceof EngineResponse) {
                if (trim($outcome->answer) === '') {
                    $retry[] = $id;

                    continue;
                }

                app(ResultRecorder::class)->record($result, $outcome, 0, batch: true);
                $this->engines->recordSuccess($batch->engine);
                $this->progress->finish($result, ResultStatus::Success);
                ResultRecorded::dispatch($result->fresh());

                $brands[$result->brand->getKey()] = $result->brand;
                $stored++;

                continue;
            }

            // Bad key, no credits…: pause the engine like a real-time failure would. A model
            // or tool the batch API rejects may still work in real time, so that is retried.
            if ($outcome->reason && ! $outcome->isTransient() && $outcome->reason !== PauseReason::ModelUnavailable) {
                $this->engines->recordFailure($batch->engine, $outcome->reason, $outcome->getMessage());
                $this->progress->finish($result, ResultStatus::Skipped, [
                    'skip_reason' => 'engine_paused:' . $outcome->reason->value,
                    'error' => $outcome->getMessage(),
                ]);

                continue;
            }

            $retry[] = $id;
        }

        foreach ($brands as $brand) {
            app(BudgetGuard::class)->enforce($brand);
        }

        // Rejected, expired or missing items: answer them in real time.
        $this->realtime($retry, $batch->engine, $batch->tenant_id);

        return $stored;
    }

    /**
     * Finish a batch; results it did not answer are retried in real time.
     */
    protected function close(Batch $batch, string $status, ?string $error = null): void
    {
        $batch->forceFill([
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
            'checked_at' => now(),
            'completed_at' => now(),
        ])->save();

        $this->realtime($batch->result_ids ?? [], $batch->engine, $batch->tenant_id);
    }

    /**
     * Queue normal jobs for results that are still pending.
     *
     * @param  array<int>  $resultIds
     */
    public function realtime(array $resultIds, string $engine, int | string | null $tenantId): void
    {
        if ($resultIds === []) {
            return;
        }

        Result::query()
            ->whereKey($resultIds)
            ->where('status', ResultStatus::Pending)
            ->pluck('id')
            ->each(fn ($id) => RunResultJob::dispatch((int) $id, $engine, $tenantId));
    }

    protected function recordPollFailure(string $engine, EngineRequestFailed $e): void
    {
        // Only problems with the key or account say anything about the engine.
        if ($e->reason && ! $e->isTransient()) {
            $this->engines->recordFailure($engine, $e->reason, $e->getMessage());
        }
    }

    /**
     * @param  array<int>  $resultIds
     * @return Collection<int, Result>
     */
    protected function pending(array $resultIds, string $engine): Collection
    {
        return Result::query()
            ->with(['brand', 'prompt'])
            ->whereKey($resultIds)
            ->where('engine', $engine)
            ->where('status', ResultStatus::Pending)
            ->get()
            ->filter(fn (Result $result) => $result->brand && $result->prompt)
            ->values();
    }

    /**
     * @param  Collection<int, Result>  $results
     */
    protected function skip(Collection $results, string $reason, ?string $error = null): void
    {
        foreach ($results as $result) {
            $this->progress->finish($result, ResultStatus::Skipped, array_filter(['skip_reason' => $reason, 'error' => $error]));
        }
    }
}
