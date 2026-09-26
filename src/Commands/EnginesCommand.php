<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;

/**
 * In a multi-tenant install this acts on every tenant, or on one with --tenant.
 */
class EnginesCommand extends Command
{
    protected $signature = 'ai-visibility:engines
        {--test : Test every enabled engine\'s key}
        {--resume= : Test an engine and resume it if the test passes}
        {--tenant= : Only this tenant (multi-tenant installs)}';

    protected $description = 'Show, test or resume AI Visibility engines';

    public function handle(EngineManager $engines, KeyResolver $keys): int
    {
        if (($engine = $this->option('resume')) && ! $engines->registry()->has($engine)) {
            $this->components->error("Unknown engine [{$engine}].");

            return self::FAILURE;
        }

        if (filled($tenant = $this->option('tenant')) && ! $engines->isKnownTenant($tenant)) {
            $this->components->error("Unknown tenant [{$tenant}].");

            return self::FAILURE;
        }

        $ok = true;

        $engines->forEachTenant($this->option('tenant'), function (int | string | null $tenant) use ($engines, $keys, $engine, &$ok) {
            $prefix = $tenant !== null ? "[tenant {$tenant}] " : '';

            if ($engine) {
                $result = $engines->testAndResume($engine);
                $result->ok
                    ? $this->components->info("{$prefix}{$engine} resumed.")
                    : $this->components->error("{$prefix}{$engine} is still paused: {$result->message}");
                $ok = $ok && $result->ok;

                return;
            }

            if ($tenant !== null) {
                $this->components->twoColumnDetail("<fg=gray>Tenant</>", (string) $tenant);
            }

            $this->table(['Engine', 'Enabled', 'Key from', 'State', $this->option('test') ? 'Test' : ''], $this->rows($engines, $keys));
        });

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function rows(EngineManager $engines, KeyResolver $keys): array
    {
        $enabled = $engines->enabled();
        $rows = [];

        foreach ($engines->registry()->all() as $key => $engine) {
            // Read-only: listing engines never creates their state.
            $state = $engines->existingState($key);
            $test = $this->option('test') && in_array($key, $enabled, true) ? $engines->test($key) : null;

            $rows[] = [
                $engine->label(),
                in_array($key, $enabled, true) ? 'yes' : 'no',
                $keys->source($key)['source'] ?? '—',
                $state ? $state->status->getLabel() . ($state->reason ? " ({$state->reason->getLabel()})" : '') : EngineStatus::Active->getLabel(),
                $test ? $test->message : '',
            ];
        }

        return $rows;
    }
}
