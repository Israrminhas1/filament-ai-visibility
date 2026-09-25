<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ai-visibility:install {--no-migrate : Publish the migrations without running them}';

    protected $description = 'Publish the AI Visibility config and migrations and run the migrations';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'ai-visibility-config']);
        $this->components->info('Published config/ai-visibility.php');

        $this->callSilently('vendor:publish', ['--tag' => 'ai-visibility-migrations']);
        $this->components->info('Published migrations');

        if (! $this->option('no-migrate')) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  1. Register the plugin in your panel: ->plugin(AiVisibilityPlugin::make())');
        $this->line('  2. Make sure a queue worker is running:  php artisan queue:work');
        $this->line('  3. Make sure the scheduler runs every minute (cron):');
        $this->line('     * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('  4. Open "AI Visibility" in your panel and follow the setup wizard.');

        return self::SUCCESS;
    }
}
