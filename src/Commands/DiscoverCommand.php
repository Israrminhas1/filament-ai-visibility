<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Jobs\DiscoverCompetitorsJob;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class DiscoverCommand extends Command
{
    protected $signature = 'ai-visibility:discover
        {--brand= : Only this brand ID}
        {--queue : Queue the work instead of running it now}';

    protected $description = 'Find, score and classify possible competitors from recent answers';

    public function handle(): int
    {
        $brands = Brand::query()->withoutGlobalScopes()
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey($id))
            ->where('is_active', true)
            ->get();

        foreach ($brands as $brand) {
            if ($this->option('queue')) {
                DiscoverCompetitorsJob::dispatch($brand->getKey(), $brand->tenant_id);

                continue;
            }

            $report = Tenancy::as($brand->tenant_id, fn () => app(CompetitorIntelligence::class)->run($brand));

            $this->components->info("{$brand->name}: {$report['candidates']} candidates, {$report['classified']} classified, names found in {$report['extracted']} answers.");

            if ($report['skipped']) {
                $this->components->warn("  {$report['skipped']}");
            }
        }

        return self::SUCCESS;
    }
}
