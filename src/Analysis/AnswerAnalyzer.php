<?php

namespace IsrarMinhas\FilamentAiVisibility\Analysis;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\Recommendation;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\Sentiment;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * One helper call per few answers: sentiment, recommendation strength and
 * descriptors for every tracked brand mentioned, plus other company names
 * for competitor discovery (so no separate extraction call is needed).
 *
 * Detection stays the source of truth for *whether* a brand is mentioned;
 * the analysis only describes mentions that detection found.
 */
class AnswerAnalyzer
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const DEFERRED = 'deferred';

    public const OFF = 'off';

    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
    ) {}

    /**
     * @return int Answers analysed.
     *
     * @throws HelperUnavailable The answers not yet analysed are marked deferred and retried later.
     */
    public function analyze(Brand $brand, ?int $runId = null, int $limit = 300): int
    {
        $results = Result::query()
            ->where('brand_id', $brand->getKey())
            ->where('status', ResultStatus::Success)
            ->whereIn('analysis_status', [self::PENDING, self::DEFERRED])
            ->when($runId, fn ($query) => $query->where('run_id', $runId))
            ->with('mentions')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $subjects = $this->subjects($brand);
        $done = 0;

        foreach ($results->chunk((int) config('ai-visibility.discovery.analysis_batch', 5)) as $batch) {
            try {
                $this->analyzeBatch($brand, $batch, $subjects);
                $done += $batch->count();
            } catch (HelperUnavailable $e) {
                Result::query()
                    ->whereKey($results->pluck('id'))
                    ->where('analysis_status', self::PENDING)
                    ->update(['analysis_status' => self::DEFERRED]);

                throw $e;
            }
        }

        return $done;
    }

    /**
     * @param  Collection<int, Result>  $batch
     * @param  array<string, array{type: string, id: int}>  $subjects
     */
    protected function analyzeBatch(Brand $brand, Collection $batch, array $subjects): void
    {
        $competitorNames = $brand->competitors()->where('is_active', true)->pluck('name')->implode(', ') ?: 'none';

        $data = $this->helper->json(Usage::PURPOSE_ANALYSIS, $this->instructions->render(Instructions::ANALYSIS, [
            'brand' => $brand->name,
            'competitors' => $competitorNames,
            'answers' => $batch->map(fn (Result $result) => "ANSWER id={$result->id}\n" . mb_substr((string) $result->answer, 0, 3000))->implode("\n\n"),
        ]), $brand, maxTokens: 3000);

        $byId = collect($data['answers'] ?? [])->keyBy(fn ($row) => (int) ($row['id'] ?? 0));

        foreach ($batch as $result) {
            $row = $byId->get($result->id);

            if (! is_array($row)) {
                // Not in the reply: try again next time rather than dropping it.
                $result->forceFill(['analysis_status' => self::DEFERRED])->save();

                continue;
            }

            $this->applyMentions($result, (array) ($row['mentions'] ?? []), $subjects);

            if ($result->entities_extracted_at === null) {
                $this->storeOtherNames($result, (array) ($row['other_names'] ?? []), $subjects);
            }

            $result->forceFill([
                'analysis_status' => self::DONE,
                'analyzed_at' => now(),
                'entities_extracted_at' => $result->entities_extracted_at ?? now(),
            ])->save();
        }
    }

    /**
     * @param  array<int, mixed>  $mentions
     * @param  array<string, array{type: string, id: int}>  $subjects
     */
    protected function applyMentions(Result $result, array $mentions, array $subjects): void
    {
        foreach ($mentions as $mention) {
            if (! is_array($mention) || ! is_string($mention['name'] ?? null)) {
                continue;
            }

            $subject = $subjects[Text::normalize($mention['name'])] ?? null;

            if (! $subject) {
                continue;
            }

            $record = $result->mentions->first(fn ($m) => $m->subject_type === $subject['type'] && (int) $m->subject_id === $subject['id']);

            if (! $record) {
                continue;
            }

            $sentiment = Sentiment::tryFrom((string) ($mention['sentiment'] ?? ''));
            $recommendation = Recommendation::tryFrom((string) ($mention['recommendation'] ?? ''));
            $score = is_numeric($mention['score'] ?? null) ? max(-1, min(1, (float) $mention['score'])) : null;

            $record->forceFill([
                'sentiment' => $sentiment?->value,
                'sentiment_score' => $score,
                'recommendation' => $recommendation?->value,
                'descriptors' => collect((array) ($mention['descriptors'] ?? []))
                    ->filter(fn ($d) => is_string($d) && trim($d) !== '')
                    ->map(fn ($d) => mb_substr(Text::squish($d), 0, 60))
                    ->unique()
                    ->take(5)
                    ->values()
                    ->all() ?: null,
            ])->save();

            if ($subject['type'] === 'brand') {
                $result->forceFill([
                    'brand_sentiment' => $sentiment?->value,
                    'brand_recommendation' => $recommendation?->value,
                ]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $names
     * @param  array<string, array{type: string, id: int}>  $subjects
     */
    protected function storeOtherNames(Result $result, array $names, array $subjects): void
    {
        $position = 0;
        $seen = [];

        foreach ($names as $name) {
            $name = Text::squish(is_string($name) ? $name : '');
            $key = Text::normalize($name);

            if ($name === '' || mb_strlen($name) > 80 || isset($subjects[$key]) || isset($seen[$key]) || ! Text::mentionsAny((string) $result->answer, [$name])) {
                continue;
            }

            $seen[$key] = true;

            $result->mentions()->create([
                'subject_type' => 'entity',
                'name_matched' => $name,
                'position' => ++$position,
                'count' => 1,
                'snippet' => $this->snippet((string) $result->answer, $name),
            ]);
        }
    }

    /**
     * Normalised name → tracked subject, for the brand and its competitors.
     *
     * @return array<string, array{type: string, id: int}>
     */
    protected function subjects(Brand $brand): array
    {
        $subjects = [];

        foreach ($brand->names() as $name) {
            $subjects[Text::normalize($name)] = ['type' => 'brand', 'id' => (int) $brand->getKey()];
        }

        foreach ($brand->competitors()->get() as $competitor) {
            foreach ($competitor->names() as $name) {
                $subjects[Text::normalize($name)] ??= ['type' => 'competitor', 'id' => (int) $competitor->getKey()];
            }
        }

        return $subjects;
    }

    protected function snippet(string $answer, string $name): ?string
    {
        if (! preg_match('/[^.\n]*(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])[^.\n]*[.]?/iu', $answer, $match)) {
            return null;
        }

        $sentence = Text::squish($match[0]);

        return mb_strlen($sentence) > 300 ? mb_substr($sentence, 0, 297) . '…' : $sentence;
    }
}
