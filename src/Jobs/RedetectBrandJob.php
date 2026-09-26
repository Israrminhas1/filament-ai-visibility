<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Runs\Redetector;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Re-checks a brand's stored answers after its names, domains or
 * competitors change. Several edits in a row queue only one job.
 */
class RedetectBrandJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly int $brandId,
        public readonly int | string | null $tenantId,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.classification', 'default'));
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->brandId}";
    }

    public function handle(): void
    {
        Tenancy::as($this->tenantId, function () {
            $brand = Brand::query()->find($this->brandId);

            if ($brand) {
                app(Redetector::class)->brand($brand);
            }
        });
    }

    /**
     * Queue a re-check if the brand has answers to correct.
     */
    public static function dispatchFor(?Brand $brand): void
    {
        if ($brand && $brand->exists && $brand->results()->exists()) {
            static::dispatch($brand->getKey(), $brand->tenant_id)->afterCommit();
        }
    }
}
