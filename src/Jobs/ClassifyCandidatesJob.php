<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use IsrarMinhas\FilamentAiVisibility\Competitors\Classifier;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Classifies chosen candidates of one brand again ("Classify again").
 * Candidates the user has meanwhile tracked, rejected or ignored are left
 * alone. It waits while discovery runs for the same brand, so the same
 * candidates are not classified (and paid for) twice.
 */
class ClassifyCandidatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Real failures allowed; waiting for a running discovery does not count.
     */
    public int $maxExceptions = 1;

    public int $timeout = 900;

    /**
     * @param  array<int>  $candidateIds
     */
    public function __construct(
        public readonly int $brandId,
        public readonly int | string | null $tenantId,
        public readonly array $candidateIds,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.classification', 'default'));
    }

    /**
     * @return array<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(DiscoverCompetitorsJob::overlapKey($this->tenantId, $this->brandId)))
                ->shared()
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(Classifier $classifier): void
    {
        Tenancy::as($this->tenantId, function () use ($classifier) {
            $brand = Brand::query()->find($this->brandId);

            $candidates = Candidate::query()
                ->where('brand_id', $this->brandId)
                ->whereKey($this->candidateIds)
                ->get()
                ->filter->isOpen()
                ->values();

            if (! $brand || $candidates->isEmpty()) {
                return;
            }

            try {
                $classifier->classify($brand, $candidates);
            } catch (HelperUnavailable $e) {
                Log::info("AI Visibility: could not classify candidates for {$brand->name}: {$e->getMessage()}");
            }
        });
    }
}
