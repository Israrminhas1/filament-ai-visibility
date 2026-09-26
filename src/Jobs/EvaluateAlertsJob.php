<?php

namespace IsrarMinhas\FilamentAiVisibility\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertEvaluator;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertType;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class EvaluateAlertsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string>|null  $only  Alert type values to check.
     */
    public function __construct(
        public readonly int $brandId,
        public readonly int | string | null $tenantId,
        public readonly ?int $runId = null,
        public readonly ?array $only = null,
    ) {
        $this->onConnection(SystemHealth::queueConnection());
        $this->onQueue(config('ai-visibility.queues.analysis', 'default'));
    }

    public function handle(AlertEvaluator $evaluator): void
    {
        Tenancy::as($this->tenantId, function () use ($evaluator) {
            $brand = Brand::query()->find($this->brandId);

            if ($brand) {
                $evaluator->evaluate(
                    $brand,
                    $this->runId ? Run::query()->find($this->runId) : null,
                    $this->only ? array_map(fn ($type) => AlertType::from($type), $this->only) : null,
                );
            }
        });
    }
}
