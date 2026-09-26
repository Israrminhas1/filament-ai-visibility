<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Support\Health\CheckResult;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;

/**
 * The scheduler and queue are checked once; engines are checked for every
 * tenant in a multi-tenant install, or for one with --tenant.
 */
class HealthCommand extends Command
{
    protected $signature = 'ai-visibility:health
        {--tenant= : Only check the engines of this tenant (multi-tenant installs)}';

    protected $description = 'Check the scheduler, queue and engines. Exits with 1 when something needs attention.';

    public function handle(SystemHealth $health, EngineManager $engines): int
    {
        $failed = false;

        foreach ($health->checks() as $check) {
            $line = "{$check->label}: {$check->message}";

            match ($check->status) {
                CheckResult::OK => $this->components->info($line),
                CheckResult::WARNING => $this->components->warn($line),
                default => $this->components->error($line),
            };

            if ($check->fix && ! $check->ok()) {
                $this->line("  Fix: {$check->fix}");
            }

            $failed = $failed || $check->status === CheckResult::FAILED;
        }

        $engines->forEachTenant($this->option('tenant'), function (int | string | null $tenant) use ($engines, &$failed) {
            $failed = ! $this->checkEngines($engines, $tenant !== null ? "[tenant {$tenant}] " : '') || $failed;
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether every enabled engine is usable.
     */
    protected function checkEngines(EngineManager $engines, string $prefix): bool
    {
        $ok = true;
        $enabled = $engines->enabled();

        if ($enabled === []) {
            $this->components->warn("{$prefix}Engines: none enabled yet. Finish setup in the panel.");
        }

        foreach ($enabled as $engine) {
            $label = $prefix . $engines->registry()->get($engine)->label();

            if ($engines->isUsable($engine)) {
                $this->components->info("{$label}: active");
            } else {
                $state = $engines->state($engine);
                $this->components->error("{$label}: paused ({$state->reason?->getLabel()})");
                $ok = false;
            }
        }

        return $ok;
    }
}
