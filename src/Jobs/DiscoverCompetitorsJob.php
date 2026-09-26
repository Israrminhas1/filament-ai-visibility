<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertEvaluator;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertType;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Extracts names, discovers and classifies candidates for one brand.
 *
 * Each job handles every unprocessed answer of the brand, not only the run
 * that queued it, so a dispatch dropped because one is already waiting loses
 * nothing: the waiting job picks those answers up. Only one job per brand
 * runs at a time; one queued meanwhile waits for it to finish.
 */
class DiscoverCompetitorsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Real failures allowed; waiting for a running job does not count.
     */
    public int $maxExceptions = 2;

    public int $timeout = 900;

    /**
     * @param  int|null  $runId  The run that queued it, for reference only.
     */
    public function __construct(
        public readonly int $brandId,
        public readonly int | string | null $tenantId,
        public readonly ?int $runId = null,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.classification', 'default'));
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->brandId}";
    }

    /**
     * @return array<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ai-visibility-discover:' . $this->uniqueId()))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Keep waiting while another discovery for the brand is still running.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(): void
    {
        Tenancy::as($this->tenantId, function () {
            $brand = Brand::query()->find($this->brandId);

            if ($brand && ! app(Settings::class)->killSwitch()) {
                // All unprocessed answers of the brand, whichever run they came from.
                app(CompetitorIntelligence::class)->run($brand);

                // Newly classified competitors can trigger alerts.
                app(AlertEvaluator::class)->evaluate($brand, only: [AlertType::NewCompetitor]);
            }
        });
    }
}
