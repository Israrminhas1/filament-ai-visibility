<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Keeps each engine under its requests-per-minute setting (per tenant),
 * releasing the job back to the queue when the minute's allowance is used.
 */
class RateLimitEngine
{
    public function handle(RunResultJob $job, Closure $next): void
    {
        $key = "ai-visibility:{$job->tenantId}:{$job->engine}";
        $limit = Tenancy::as($job->tenantId, fn () => app(EngineManager::class)->requestsPerMinute($job->engine));

        // Released for exactly as long as the limiter needs. The job's retry window is
        // sized to the run and its dispatch is spread out (RunResultJob::dispatchPaced()), so
        // waiting here does not use up the time the job has to be answered.
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $job->release(max(1, RateLimiter::availableIn($key)));

            return;
        }

        RateLimiter::hit($key, 60);

        $next($job);
    }
}
