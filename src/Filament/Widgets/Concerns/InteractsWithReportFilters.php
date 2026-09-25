<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use IsrarMinhas\FilamentAiVisibility\Reports\Metrics;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;

trait InteractsWithReportFilters
{
    use InteractsWithPageFilters;

    protected function reportFilters(): ?ReportFilters
    {
        return ReportFilters::fromState($this->pageFilters);
    }

    protected function metrics(): Metrics
    {
        return app(Metrics::class);
    }
}
