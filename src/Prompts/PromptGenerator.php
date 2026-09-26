<?php

namespace IsrarMinhas\FilamentAiVisibility\Prompts;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Turns keywords (or just the brand profile) into realistic questions people
 * ask AI assistants, then filters out weak ones: rule checks first, then an
 * optional AI review. Saved as "suggested" for the user to approve;
 * rejected ideas are kept with the reason.
 */
class PromptGenerator
{
    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
        protected PromptQualityGate $gate,
        protected Settings $settings,
    ) {}

    /**
     * @param  array<int>|null  $keywordIds  Specific keywords; null picks the brand's top keywords.
     * @param  array<string>|null  $intents
     * @return array{suggested: Collection<int, Prompt>, rejected: Collection<int, Prompt>, candidates: Collection<int, array{text: string, intent: PromptIntent, score: ?int, reason: ?string, keyword_ids: array<int>, passed: bool}>}
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable
     */
    public function generate(
        Brand $brand,
        ?int $count = null,
        ?array $intents = null,
        ?string $persona = null,
        ?Topic $topic = null,
        ?array $keywordIds = null,
        bool $useKeywords = true,
        bool $save = true,
    ): array {
        $count = max(1, min(50, $count ?? (int) $this->settings->get('generation.count', 10)));
        $intents = $this->intents($intents ?? (array) $this->settings->get('generation.intents', []));
        $persona ??= $this->settings->get('generation.persona');
        $keywords = $useKeywords ? $this->keywords($brand, $keywordIds, $count) : collect();
        $existing = $brand->prompts()->where('status', '!=', PromptStatus::Rejected)->pluck('text')->all();

        $data = $this->helper->json(Usage::PURPOSE_GENERATION, $this->instructions->render(Instructions::GENERATION, [
            'brand' => $brand->name,
            'domain' => $brand->primaryDomain() ?? 'unknown',
            'description' => $brand->description ?: 'not given',
            'industry' => $brand->industry ?: 'not given',
            'market' => $brand->market ?: 'not given',
            'persona' => $persona ?: 'a typical customer',
            'competitors' => $brand->competitors()->pluck('name')->implode(', ') ?: 'none listed',
            'keywords' => $keywords->isEmpty() ? '(none: use the brand profile)' : $keywords->map(fn (Keyword $k) => '- ' . $k->keyword . ($k->search_volume ? " ({$k->search_volume} searches/month)" : ($k->impressions ? " ({$k->impressions} impressions)" : '')))->implode("\n"),
            'count' => (string) $count,
            'topic' => $topic?->name ?? 'any',
            'intents' => collect($intents)->map(fn (PromptIntent $i) => "- {$i->value}: {$i->getLabel()}")->implode("\n"),
            'existing_prompts' => $existing ? collect($existing)->take(100)->map(fn ($t) => "- {$t}")->implode("\n") : '(none)',
        ]), $brand, maxTokens: 3000);

        $keywordsByText = $keywords->keyBy(fn (Keyword $k) => Text::normalize($k->keyword));
        $candidates = collect();
        $accepted = $existing;

        foreach (array_slice((array) ($data['prompts'] ?? []), 0, $count * 2) as $row) {
            $text = Text::squish(is_array($row) ? (string) ($row['text'] ?? '') : (string) $row);
            $intent = PromptIntent::tryFrom((string) ($row['intent'] ?? '')) ?? $intents[0];
            $keyword = is_array($row) && is_string($row['keyword'] ?? null) ? $keywordsByText->get(Text::normalize($row['keyword'])) : null;

            if ($text === '') {
                continue;
            }

            $reason = $this->gate->check($text, $brand, $intent, $accepted);

            if ($reason === null) {
                $accepted[] = $text;
            }

            $candidates->push([
                'text' => $text,
                'intent' => $intent,
                'score' => null,
                'reason' => $reason,
                'keyword_ids' => $keyword ? [$keyword->getKey()] : [],
                'passed' => $reason === null,
            ]);
        }

        $candidates = $this->review($brand, $candidates);

        if (! $save) {
            return ['suggested' => collect(), 'rejected' => collect(), 'candidates' => $candidates];
        }

        $suggested = collect();
        $rejected = collect();

        foreach ($candidates as $candidate) {
            if ($brand->prompts()->where('text_hash', Text::hash($candidate['text']))->exists()) {
                continue;
            }

            $prompt = $brand->prompts()->create([
                'text' => $candidate['text'],
                'intent' => $candidate['intent'],
                'topic_id' => $topic?->getKey(),
                'source' => PromptSource::Generated,
                'status' => $candidate['passed'] ? PromptStatus::Suggested : PromptStatus::Rejected,
                'quality_score' => $candidate['score'],
                'quality_reason' => $candidate['reason'] ? mb_substr($candidate['reason'], 0, 255) : null,
            ]);

            if ($candidate['keyword_ids']) {
                $prompt->keywords()->syncWithoutDetaching($candidate['keyword_ids']);
            }

            $candidate['passed'] ? $suggested->push($prompt) : $rejected->push($prompt);
        }

        return ['suggested' => $suggested, 'rejected' => $rejected, 'candidates' => $candidates];
    }

    /**
     * AI review of the candidates that passed the rules.
     *
     * @param  Collection<int, array>  $candidates
     * @return Collection<int, array>
     */
    protected function review(Brand $brand, Collection $candidates): Collection
    {
        $passing = $candidates->filter(fn ($c) => $c['passed']);

        if ($passing->isEmpty() || ! $this->settings->get('generation.ai_review', true)) {
            return $candidates;
        }

        $data = $this->helper->json(Usage::PURPOSE_GENERATION, $this->instructions->render(Instructions::QUALITY_REVIEW, [
            'brand' => $brand->name,
            'description' => $brand->description ?: 'not given',
            'market' => $brand->market ?: 'not given',
            'prompts' => $passing->map(fn ($c, $index) => "{$index}: {$c['text']}")->implode("\n"),
        ]), $brand, maxTokens: 2000);

        $reviews = collect($data['reviews'] ?? [])->keyBy(fn ($r) => (int) ($r['id'] ?? -1));
        $minimum = (int) $this->settings->get('generation.min_quality', 4);

        return $candidates->map(function (array $candidate, int $index) use ($reviews, $minimum) {
            $review = $reviews->get($index);

            if (! $candidate['passed'] || ! $review) {
                return $candidate;
            }

            $score = max(1, min(5, (int) ($review['score'] ?? 0)));

            return [
                ...$candidate,
                'score' => $score,
                'reason' => $score < $minimum ? ('Low quality (' . $score . '/5): ' . ($review['reason'] ?? '')) : ($review['reason'] ?? null),
                'passed' => $score >= $minimum,
            ];
        });
    }

    /**
     * @param  array<int>|null  $ids
     * @return Collection<int, Keyword>
     */
    protected function keywords(Brand $brand, ?array $ids, int $count): Collection
    {
        return $brand->keywords()
            ->where('status', 'active')
            ->when($ids !== null, fn ($query) => $query->whereKey($ids), fn ($query) => $query->where('is_branded', false))
            ->orderByDesc('search_volume')
            ->orderByDesc('impressions')
            ->limit($ids !== null ? count($ids) : max(10, $count * 2))
            ->get();
    }

    /**
     * @param  array<string|PromptIntent>  $values
     * @return array<PromptIntent>
     */
    protected function intents(array $values): array
    {
        $intents = array_values(array_filter(array_map(
            fn ($value) => $value instanceof PromptIntent ? $value : PromptIntent::tryFrom((string) $value),
            $values,
        )));

        return $intents ?: [PromptIntent::Discovery];
    }
}
