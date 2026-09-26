<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Carbon\CarbonImmutable;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;

/**
 * The data for a scheduled email/PDF report.
 */
class ReportBuilder
{
    public function __construct(
        protected Metrics $metrics,
    ) {}

    /**
     * @param  array<string>  $sections
     * @return array<string, mixed>
     */
    public function build(Brand $brand, int $days, array $sections): array
    {
        $filters = new ReportFilters($brand, CarbonImmutable::now()->subDays($days - 1)->startOfDay(), CarbonImmutable::now());
        $competitors = app(CompetitorMetrics::class);

        $data = [
            'brand' => $brand,
            'from' => $filters->from,
            'until' => $filters->until,
            'days' => $days,
            'sections' => $sections,
        ];

        if (in_array('summary', $sections, true)) {
            $data['summary'] = $this->metrics->summary($filters);
            $data['previous'] = $this->metrics->summary($filters->previous());
            $data['reach'] = $this->metrics->reach($filters);
        }

        if (in_array('competitors', $sections, true)) {
            $data['leaderboard'] = $competitors->leaderboard($filters)->take(8);
        }

        if (in_array('opportunities', $sections, true)) {
            $opportunities = $competitors->opportunities($filters);
            $data['opportunities'] = $opportunities['prompts']->take(5);
            $data['featureSources'] = $opportunities['sources']->take(5)->map(fn ($row) => $row + ['category_label' => SourceCategory::label($row['category'])]);
        }

        if (in_array('sources', $sections, true)) {
            $data['sources'] = $this->metrics->topSources($filters, 8)->map(fn ($row) => [
                'domain' => $row->domain,
                'category' => SourceCategory::label($row->category),
                'answers' => $row->answers,
            ]);
        }

        if (in_array('prompts', $sections, true)) {
            $prompts = $this->metrics->prompts($filters);
            $data['gained'] = $prompts->filter(fn ($p) => ($p['change'] ?? 0) > 0)->sortByDesc('change')->take(5)->values();
            $data['lost'] = $prompts->filter(fn ($p) => ($p['change'] ?? 0) < 0)->sortBy('change')->take(5)->values();
        }

        if (in_array('perception', $sections, true)) {
            $data['perception'] = $competitors->perception($filters);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function forSchedule(ReportSchedule $schedule): array
    {
        return $this->build($schedule->brand, $schedule->periodDays(), $schedule->enabledSections());
    }
}
