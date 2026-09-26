<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Runs\Redetector;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

/**
 * Re-checks stored answers with the current brand and competitor names.
 * Runs automatically when those change; this is for doing it by hand.
 */
class RedetectCommand extends Command
{
    protected $signature = 'ai-visibility:redetect {--brand= : Only this brand ID}';

    protected $description = 'Re-check stored AI Visibility answers for brand and competitor mentions';

    public function handle(Redetector $redetector): int
    {
        $brands = Brand::query()->withoutGlobalScopes()
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        foreach ($brands as $brand) {
            $changed = Tenancy::as($brand->tenant_id, fn () => $redetector->brand($brand));

            $this->components->twoColumnDetail($brand->name, "{$changed} answers changed");
        }

        if ($brands->isEmpty()) {
            $this->components->warn('No brands found.');
        }

        return self::SUCCESS;
    }
}
