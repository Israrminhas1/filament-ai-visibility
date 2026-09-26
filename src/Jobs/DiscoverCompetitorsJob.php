<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
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

    /**
     * Queue discovery for a brand unless one is already waiting.
     *
     * @return bool Whether a job was queued.
     */
    public static function queueFor(int $brandId, int | string | null $tenantId, ?int $runId = null): bool
    {
        $job = new static($brandId, $tenantId, $runId);

        if (! (new UniqueLock(app(Cache::class)))->acquire($job)) {
            return false;
        }

        app(Dispatcher::class)->dispatch($job);

        return true;
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->brandId}";
    }

    /**
     * A job lost without running (a crashed worker, a flushed queue) stops
     * blocking new ones after this many seconds.
     */
    public function uniqueFor(): int
    {
        return 3600;
    }

    /**
     * The lock shared with ClassifyCandidatesJob, so the same candidates are
     * never classified (and paid for) twice at the same time.
     */
    public static function overlapKey(int | string | null $tenantId, int $brandId): string
    {
        return "ai-visibility-discover:{$tenantId}:{$brandId}";
    }

    /**
     * @return array<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(static::overlapKey($this->tenantId, $this->brandId)))
                ->shared()
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
