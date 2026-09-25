<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Support\Health\CheckResult;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;

class HealthCommand extends Command
{
    protected $signature = 'ai-visibility:health';

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

        $enabled = $engines->enabled();

        if ($enabled === []) {
            $this->components->warn('Engines: none enabled yet. Finish setup in the panel.');
        }

        foreach ($enabled as $engine) {
            $label = $engines->registry()->get($engine)->label();

            if ($engines->isUsable($engine)) {
                $this->components->info("{$label}: active");
            } else {
                $state = $engines->state($engine);
                $this->components->error("{$label}: paused ({$state->reason?->getLabel()})");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
