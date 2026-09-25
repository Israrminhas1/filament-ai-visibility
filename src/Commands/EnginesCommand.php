<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;

class EnginesCommand extends Command
{
    protected $signature = 'ai-visibility:engines
        {--test : Test every enabled engine\'s key}
        {--resume= : Test an engine and resume it if the test passes}';

    protected $description = 'Show, test or resume AI Visibility engines';

    public function handle(EngineManager $engines, KeyResolver $keys): int
    {
        if ($engine = $this->option('resume')) {
            if (! $engines->registry()->has($engine)) {
                $this->components->error("Unknown engine [{$engine}].");

                return self::FAILURE;
            }

            $result = $engines->testAndResume($engine);
            $result->ok
                ? $this->components->info("{$engine} resumed.")
                : $this->components->error("{$engine} is still paused: {$result->message}");

            return $result->ok ? self::SUCCESS : self::FAILURE;
        }

        $enabled = $engines->enabled();
        $rows = [];

        foreach ($engines->registry()->all() as $key => $engine) {
            $state = $engines->state($key);
            $test = $this->option('test') && in_array($key, $enabled, true) ? $engines->test($key) : null;

            $rows[] = [
                $engine->label(),
                in_array($key, $enabled, true) ? 'yes' : 'no',
                $keys->source($key)['source'] ?? '—',
                $state->status->getLabel() . ($state->reason ? " ({$state->reason->getLabel()})" : ''),
                $test ? $test->message : '',
            ];
        }

        $this->table(['Engine', 'Enabled', 'Key from', 'State', $this->option('test') ? 'Test' : ''], $rows);

        return self::SUCCESS;
    }
}
