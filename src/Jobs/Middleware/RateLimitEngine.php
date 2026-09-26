<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Keeps each engine under its requests-per-minute setting (per tenant),
 * holding a job until its scheduled slot and releasing it back to the
 * queue when the minute's allowance is used.
 */
class RateLimitEngine
{
    public function handle(RunResultJob $job, Closure $next): void
    {
        // Picked up before its slot (long waits are queued in steps): wait for it without calling anything.
        // A release is not an exception and the job retries by time (retryUntil), so waiting costs no tries.
        if ($job->availableAt !== null && ($wait = $job->availableAt - now()->getTimestamp()) > 0) {
            $job->release(min($wait, RunResultJob::maxDelay()));

            return;
        }

        $key = "ai-visibility:{$job->tenantId}:{$job->engine}";
        $limit = Tenancy::as($job->tenantId, fn () => app(EngineManager::class)->requestsPerMinute($job->engine));

        // Released for exactly as long as the limiter needs. Jobs are spread out on a schedule shared
        // by all runs (RunResultJob::dispatchPaced()) and each job's retry window starts at its slot,
        // so waiting here does not use up the time the job has to be answered.
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $job->release(max(1, RateLimiter::availableIn($key)));

            return;
        }

        RateLimiter::hit($key, 60);

        $next($job);
    }
}
