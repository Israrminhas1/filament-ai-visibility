<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use Illuminate\Support\Facades\Log;
use IsrarMinhas\FilamentAiVisibility\Analysis\AnswerAnalyzer;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

/**
 * The whole pipeline for one brand: extract names → discover → classify.
 * AI steps are skipped (not failed) when no helper engine is available.
 */
class CompetitorIntelligence
{
    public function __construct(
        protected AnswerAnalyzer $analyzer,
        protected NameExtractor $extractor,
        protected Discovery $discovery,
        protected Classifier $classifier,
        protected Settings $settings,
    ) {}

    /**
     * @return array{extracted: int, candidates: int, classified: int, skipped: ?string}
     */
    public function run(Brand $brand, ?int $runId = null): array
    {
        $report = ['analyzed' => 0, 'extracted' => 0, 'candidates' => 0, 'classified' => 0, 'skipped' => null];

        // Analysis also collects other company names, so extraction is only needed without it.
        try {
            if ($this->settings->get('analysis.enabled', true)) {
                $report['analyzed'] = $this->analyzer->analyze($brand, $runId);
            }

            if ($this->settings->get('discovery.enabled', true) && $this->settings->get('discovery.extract_names', true)) {
                $report['extracted'] = $this->extractor->extract($brand, $runId);
            }
        } catch (HelperUnavailable $e) {
            $report['skipped'] = $e->getMessage();
        }

        if (! $this->settings->get('discovery.enabled', true)) {
            return $report;
        }

        $report['candidates'] = $this->discovery->discover($brand);

        if ($report['skipped'] === null && $this->settings->get('discovery.classify', true)) {
            try {
                $report['classified'] = $this->classifier->classify($brand);
            } catch (HelperUnavailable $e) {
                $report['skipped'] = $e->getMessage();
            }
        }

        if ($report['skipped']) {
            Log::info("AI Visibility: competitor AI steps skipped for {$brand->name}: {$report['skipped']}");
        }

        return $report;
    }
}
