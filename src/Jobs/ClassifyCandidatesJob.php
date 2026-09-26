<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use IsrarMinhas\FilamentAiVisibility\Competitors\Classifier;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Classifies chosen candidates of one brand again ("Classify again").
 */
class ClassifyCandidatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

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

    public function handle(Classifier $classifier): void
    {
        Tenancy::as($this->tenantId, function () use ($classifier) {
            $brand = Brand::query()->find($this->brandId);

            $candidates = Candidate::query()
                ->where('brand_id', $this->brandId)
                ->whereKey($this->candidateIds)
                ->get();

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
