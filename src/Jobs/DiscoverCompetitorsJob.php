<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class DiscoverCompetitorsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

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

    public function handle(): void
    {
        Tenancy::as($this->tenantId, function () {
            $brand = Brand::query()->find($this->brandId);

            if ($brand && ! app(Settings::class)->killSwitch()) {
                app(CompetitorIntelligence::class)->run($brand, $this->runId);
            }
        });
    }
}
