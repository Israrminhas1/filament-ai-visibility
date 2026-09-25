<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Widgets;

use Filament\Widgets\Widget;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\Concerns\InteractsWithReportFilters;

class OwnPagesCited extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'ai-visibility::widgets.own-pages';

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $filters = $this->reportFilters();

        return [
            'brand' => $filters?->brand,
            'rows' => $filters ? $this->metrics()->ownPagesCited($filters, 15) : collect(),
        ];
    }
}
