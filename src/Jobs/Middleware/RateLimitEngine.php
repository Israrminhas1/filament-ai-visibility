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

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $job->release(max(1, RateLimiter::availableIn($key)));

            return;
        }

        RateLimiter::hit($key, 60);

        $next($job);
    }
}
