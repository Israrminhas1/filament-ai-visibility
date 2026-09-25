<?php

namespace IsrarMinhas\FilamentAiVisibility\Competitors;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Events\CandidateClassified;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Classification;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

/**
 * Labels candidates (direct competitor, review site, marketplace…) from
 * evidence: their website text, or the sentences they were mentioned in.
 * The user's past corrections for the brand are included as examples.
 */
class Classifier
{
    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
        protected EvidenceFetcher $evidence,
        protected CandidateActions $actions,
        protected Settings $settings,
    ) {}

    /**
     * Classify the brand's highest-scoring open candidates that need it.
     *
     * @param  Collection<int, Candidate>|null  $candidates  Specific candidates; default the top N due.
     * @return int Number classified.
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable
     */
    public function classify(Brand $brand, ?Collection $candidates = null): int
    {
        $candidates ??= $this->due($brand);
        $count = 0;

        foreach ($candidates->chunk((int) config('ai-visibility.discovery.classification_batch', 10)) as $batch) {
            $count += $this->classifyBatch($brand, $batch->values());
        }

        return $count;
    }

    /**
     * @return Collection<int, Candidate>
     */
    public function due(Brand $brand): Collection
    {
        $staleBefore = now()->subDays((int) $this->settings->get('discovery.reclassify_days', 90));

        return Candidate::query()
            ->where('brand_id', $brand->getKey())
            ->where('score', '>', 0)
            ->where(fn ($query) => $query
                ->where('status', Candidate::STATUS_NEW)
                ->orWhere(fn ($q) => $q->where('status', Candidate::STATUS_CLASSIFIED)->where('classified_at', '<', $staleBefore)))
            ->orderByDesc('score')
            ->limit((int) $this->settings->get('discovery.top_n', 25))
            ->get();
    }

    /**
     * @param  Collection<int, Candidate>  $batch
     */
    protected function classifyBatch(Brand $brand, Collection $batch): int
    {
        $evidence = $batch->mapWithKeys(fn (Candidate $candidate) => [$candidate->getKey() => $this->evidenceFor($candidate)]);

        $data = $this->helper->json(Usage::PURPOSE_CLASSIFICATION, $this->instructions->render(Instructions::CLASSIFICATION, [
            'brand' => $brand->name,
            'domain' => $brand->primaryDomain() ?? 'unknown',
            'description' => $brand->description ?: 'not given',
            'industry' => $brand->industry ?: 'not given',
            'market' => $brand->market ?: 'not given',
            'competitors' => $brand->competitors()->pluck('name')->implode(', ') ?: 'none yet',
            'labels' => collect(CompetitorLabel::cases())->map(fn ($label) => "- {$label->value}: {$label->getDescription()}")->implode("\n"),
            'examples' => $this->examples($brand),
            'candidates' => $batch->map(fn (Candidate $candidate) => $this->describe($candidate, $evidence[$candidate->getKey()]))->implode("\n\n"),
        ]), $brand, maxTokens: 3000);

        $helper = app(EngineManager::class)->helper();
        $results = collect($data['results'] ?? [])->keyBy(fn ($row) => (string) ($row['key'] ?? ''));
        $count = 0;

        foreach ($batch as $candidate) {
            $row = $results->get('c' . $candidate->getKey());

            if (! $row) {
                continue;
            }

            $label = CompetitorLabel::tryFrom((string) ($row['label'] ?? '')) ?? CompetitorLabel::Unrelated;
            $confidence = in_array($row['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $row['confidence'] : 'low';

            $candidate->classifications()->create([
                'label' => $label,
                'is_direct_competitor' => $label === CompetitorLabel::DirectCompetitor,
                'confidence' => $confidence,
                'company_name' => isset($row['company_name']) ? mb_substr((string) $row['company_name'], 0, 255) : null,
                'offering_summary' => $row['offering_summary'] ?? null,
                'reason' => $row['reason'] ?? null,
                'evidence' => $evidence[$candidate->getKey()],
                'engine' => $helper ? $helper['engine']->key() : null,
                'model' => $helper['model'] ?? null,
            ]);

            $candidate->forceFill([
                'label' => $label,
                'confidence' => $confidence,
                'status' => Candidate::STATUS_CLASSIFIED,
                'classified_at' => now(),
            ])->save();

            $this->actions->applySourceCategory($candidate);

            CandidateClassified::dispatch($candidate);

            if ($this->settings->get('discovery.auto_accept', false) && $label === CompetitorLabel::DirectCompetitor && $confidence === 'high') {
                rescue(fn () => $this->actions->accept($candidate), report: false);
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    protected function evidenceFor(Candidate $candidate): array
    {
        $evidence = [];

        if ($candidate->domain) {
            $profile = $this->evidence->profile($candidate->domain);

            $evidence['website'] = $profile->status === 'ok' ? array_filter([
                'url' => 'https://' . $candidate->domain,
                'title' => $profile->title,
                'description' => $profile->description,
                'headings' => $profile->headings,
                'excerpt' => mb_substr((string) $profile->excerpt, 0, 1200),
            ]) : ['url' => 'https://' . $candidate->domain, 'note' => 'The website could not be read.'];
        }

        // The sentences the AI answers used to describe it.
        $evidence['mentions'] = ResultMention::query()
            ->where('subject_type', 'entity')
            ->whereRaw('LOWER(name_matched) = ?', [mb_strtolower($candidate->name)])
            ->whereNotNull('snippet')
            ->latest('id')
            ->limit(3)
            ->pluck('snippet')
            ->all();

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    protected function describe(Candidate $candidate, array $evidence): string
    {
        $lines = [
            "key: c{$candidate->getKey()}",
            "name: {$candidate->name}",
            'domain: ' . ($candidate->domain ?? 'unknown'),
            "seen in {$candidate->answers} answers to {$candidate->prompts} prompts on " . count($candidate->engines ?? []) . ' engine(s)',
        ];

        if ($website = $evidence['website'] ?? null) {
            $lines[] = 'website: ' . json_encode($website, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($evidence['mentions'] ?? []) {
            $lines[] = 'mentioned as: ' . implode(' | ', $evidence['mentions']);
        }

        return implode("\n", $lines);
    }

    /**
     * The brand's most recent corrections, so the classifier learns what the user means.
     */
    protected function examples(Brand $brand): string
    {
        $corrections = Classification::query()
            ->whereNotNull('override_label')
            ->whereHas('candidate', fn ($query) => $query->where('brand_id', $brand->getKey()))
            ->with('candidate')
            ->latest('overridden_at')
            ->limit(10)
            ->get();

        if ($corrections->isEmpty()) {
            return '';
        }

        return "CORRECTIONS FROM THE USER (follow the same judgement)\n" . $corrections
            ->map(fn (Classification $c) => "- {$c->candidate->name}" . ($c->candidate->domain ? " ({$c->candidate->domain})" : '') . " is {$c->override_label->value}, not {$c->label->value}")
            ->implode("\n");
    }
}
