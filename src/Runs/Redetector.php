<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\Analysis\AnswerAnalyzer;
use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

/**
 * Checks stored answers again with the brand's current names, aliases,
 * domains and competitors, so fixing a name (or adding a competitor)
 * corrects past results too. No AI calls.
 */
class Redetector
{
    /**
     * Mention columns written by answer analysis.
     */
    protected const ANALYSIS_FIELDS = ['sentiment', 'sentiment_score', 'recommendation', 'descriptors'];

    public function __construct(
        protected MentionDetector $mentions,
        protected SourceCategory $categories,
    ) {}

    /**
     * @return int Answers whose brand result changed.
     */
    public function brand(Brand $brand): int
    {
        $competitors = $brand->competitors()->where('is_active', true)->get();
        $changed = 0;

        $brand->results()
            ->where('status', ResultStatus::Success)
            ->whereNotNull('answer')
            ->with('citations')
            ->chunkById(200, function ($results) use ($brand, $competitors, &$changed) {
                foreach ($results as $result) {
                    $changed += (int) $this->result($result, $brand, $competitors);
                }
            });

        return $changed;
    }

    /**
     * @param  iterable<\IsrarMinhas\FilamentAiVisibility\Models\Competitor>  $competitors
     */
    protected function result(Result $result, Brand $brand, iterable $competitors): bool
    {
        $competitors = collect($competitors);
        $mentions = $this->mentions->detect((string) $result->answer, $brand, $competitors);
        $brandMention = collect($mentions)->firstWhere('subjectType', 'brand');
        $wasMentioned = (bool) $result->brand_mentioned;

        DB::transaction(function () use ($result, $brand, $competitors, $mentions, $brandMention) {
            // Tracked mentions are rebuilt; untracked names ("entity") stay unless now tracked.
            // Sentiment and descriptors from answer analysis are kept for subjects still found.
            $analysis = $result->mentions()
                ->whereIn('subject_type', ['brand', 'competitor'])
                ->get()
                ->keyBy(fn ($mention) => $mention->subject_type . ':' . $mention->subject_id)
                ->map(fn ($mention) => $mention->only(self::ANALYSIS_FIELDS));

            $result->mentions()->whereIn('subject_type', ['brand', 'competitor'])->delete();
            $unanalysed = false;

            $matched = collect($mentions)->map(fn ($mention) => mb_strtolower($mention->nameMatched))->all();

            if ($matched !== []) {
                $result->mentions()
                    ->where('subject_type', 'entity')
                    ->whereRaw('LOWER(name_matched) IN (' . implode(',', array_fill(0, count($matched), '?')) . ')', $matched)
                    ->delete();
            }

            foreach ($mentions as $mention) {
                $kept = $analysis->get($mention->subjectType . ':' . $mention->subjectId);
                $unanalysed = $unanalysed || $kept === null;

                $result->mentions()->create([
                    'subject_type' => $mention->subjectType,
                    'subject_id' => $mention->subjectId,
                    'name_matched' => $mention->nameMatched,
                    'position' => $mention->position,
                    'count' => $mention->count,
                    'snippet' => $mention->snippet,
                    ...($kept ?? []),
                ]);
            }

            // Newly found brand or competitor mentions have no sentiment yet: analyse the answer again.
            if ($unanalysed && in_array($result->analysis_status, [AnswerAnalyzer::DONE, AnswerAnalyzer::FAILED], true)) {
                $result->analysis_status = AnswerAnalyzer::PENDING;
            }

            $brandCited = false;

            foreach ($result->citations as $citation) {
                $isBrand = Domains::matches($citation->url, $brand->domains ?? []);
                $competitor = $isBrand ? null : $competitors->first(fn ($c) => Domains::matches($citation->url, $c->domains ?? []));
                $brandCited = $brandCited || $isBrand;

                $citation->forceFill([
                    'is_brand' => $isBrand,
                    'competitor_id' => $competitor?->getKey(),
                    'category' => $this->categories->categorize($citation->url, $brand, $isBrand, $competitor?->getKey()),
                ])->save();
            }

            $result->forceFill([
                'brand_mentioned' => $brandMention !== null,
                'brand_position' => $brandMention?->position,
                'brand_mention_count' => $brandMention?->count ?? 0,
                'brand_cited' => $brandCited,
                // The brand's sentiment only stands while the brand is still found.
                'brand_sentiment' => $brandMention ? $result->brand_sentiment : null,
                'brand_recommendation' => $brandMention ? $result->brand_recommendation : null,
            ])->save();
        });

        return $wasMentioned !== ($brandMention !== null);
    }
}
