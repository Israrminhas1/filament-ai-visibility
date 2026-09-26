<?php

namespace IsrarMinhas\FilamentAiVisibility\Analysis;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
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

    /**
     * Gave up after repeated unusable replies for this answer.
     */
    public const FAILED = 'failed';

    /**
     * Prefix for answers the helper could not handle yet, followed by the
     * number of failed attempts ("retry:1").
     */
    public const RETRY = 'retry:';

    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
    ) {}

    /**
     * A reply that is not usable JSON only affects its own answers: the batch
     * is retried one answer at a time, and an answer that keeps failing (or
     * that the helper keeps leaving out) is marked failed after a few attempts.
     *
     * @return int Answers analysed.
     *
     * @throws HelperUnavailable No helper can be used right now (no key, budget, outage).
     *                           The answers not yet analysed are marked deferred and retried later.
     */
    public function analyze(Brand $brand, ?int $runId = null, int $limit = 300): int
    {
        $results = Result::query()
            ->where('brand_id', $brand->getKey())
            ->where('status', ResultStatus::Success)
            ->where(fn ($query) => $query
                ->whereIn('analysis_status', [self::PENDING, self::DEFERRED])
                ->orWhere('analysis_status', 'like', self::RETRY . '%'))
            ->when($runId, fn ($query) => $query->where('run_id', $runId))
            ->with('mentions')
            // New answers first, then the backlog oldest first, so every answer gets its turn.
            ->orderByRaw('case when analysis_status = ? then 0 else 1 end', [self::PENDING])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $subjects = $this->subjects($brand);
        $done = 0;

        foreach ($results->chunk(max(1, (int) config('ai-visibility.discovery.analysis_batch', 5))) as $batch) {
            try {
                $done += $this->analyzeBatch($brand, $batch->values(), $subjects);
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
     * @return int Answers analysed.
     *
     * @throws HelperUnavailable
     */
    protected function analyzeBatch(Brand $brand, Collection $batch, array $subjects): int
    {
        $competitorNames = $brand->competitors()->where('is_active', true)->pluck('name')->implode(', ') ?: 'none';

        $data = $this->helper->complete(Usage::PURPOSE_ANALYSIS, $this->instructions->render(Instructions::ANALYSIS, [
            'brand' => $brand->name,
            'competitors' => $competitorNames,
            'answers' => $batch->map(fn (Result $result) => "ANSWER id={$result->id}\n" . mb_substr(Text::clean((string) $result->answer), 0, 3000))->implode("\n\n"),
        ]), $brand, maxTokens: static::maxTokens($batch->count()), json: true)->json();

        if (! is_array($data['answers'] ?? null)) {
            // Unusable reply (often cut off): try each answer on its own once.
            if ($batch->count() > 1) {
                return $batch->sum(fn (Result $result) => $this->analyzeBatch($brand, collect([$result]), $subjects));
            }

            $this->recordFailure($batch->first());

            return 0;
        }

        $byId = collect($data['answers'])->filter(fn ($row) => is_array($row))->keyBy(fn ($row) => (int) ($row['id'] ?? 0));
        $done = 0;

        foreach ($batch as $result) {
            $row = $byId->get($result->id);

            if (! is_array($row)) {
                // Left out of the reply: try again next time, but not forever.
                $this->recordFailure($result);

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

            $done++;
        }

        return $done;
    }

    /**
     * Room for about 600 tokens of reply per answer.
     */
    public static function maxTokens(int $answers): int
    {
        return max(2000, min(8000, 600 * $answers));
    }

    /**
     * Count a failed attempt; after the configured number the answer is marked failed.
     */
    protected function recordFailure(Result $result): void
    {
        $status = (string) $result->analysis_status;
        $attempts = (str_starts_with($status, self::RETRY) ? (int) substr($status, strlen(self::RETRY)) : 0) + 1;
        $max = max(1, (int) config('ai-visibility.discovery.analysis_attempts', 3));

        $result->forceFill(['analysis_status' => $attempts >= $max ? self::FAILED : self::RETRY . $attempts])->save();
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

        foreach (MentionDetector::terms($brand) as $name) {
            $subjects[Text::normalize($name)] = ['type' => 'brand', 'id' => (int) $brand->getKey()];
        }

        foreach ($brand->competitors()->get() as $competitor) {
            foreach (MentionDetector::terms($competitor) as $name) {
                $subjects[Text::normalize($name)] ??= ['type' => 'competitor', 'id' => (int) $competitor->getKey()];
            }
        }

        return $subjects;
    }

    protected function snippet(string $answer, string $name): ?string
    {
        if (! preg_match('/[^.\n]*' . Text::namePattern(Text::clean($name)) . '[^.\n]*[.]?/iu', Text::clean($answer), $match)) {
            return null;
        }

        $sentence = Text::squish($match[0]);

        return mb_strlen($sentence) > 300 ? mb_substr($sentence, 0, 297) . '…' : $sentence;
    }
}
