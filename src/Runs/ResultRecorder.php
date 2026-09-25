<?php

namespace IsrarMinhas\FilamentAiVisibility\Runs;

use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\Analysis\AnswerAnalyzer;
use IsrarMinhas\FilamentAiVisibility\Detection\CitationExtractor;
use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\Pricing;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;

/**
 * Stores an engine answer: detection, citations, cost and usage.
 */
class ResultRecorder
{
    public function __construct(
        protected MentionDetector $mentions,
        protected CitationExtractor $citations,
        protected Pricing $pricing,
        protected Spend $spend,
        protected SourceCategory $categories,
    ) {}

    public function record(Result $result, EngineResponse $response, int $durationMs): Result
    {
        $brand = $result->brand;
        $competitors = $brand->competitors()->where('is_active', true)->get();

        $mentions = $this->mentions->detect($response->answer, $brand, $competitors);
        $citations = $this->citations->extract($response);
        $brandMention = collect($mentions)->firstWhere('subjectType', 'brand');

        $cost = $this->pricing->cost($result->engine, $response->model, $response->inputTokens, $response->outputTokens, $response->searches);

        DB::transaction(function () use ($result, $response, $durationMs, $brand, $competitors, $mentions, $citations, $brandMention, $cost) {
            foreach ($mentions as $mention) {
                $result->mentions()->create([
                    'subject_type' => $mention->subjectType,
                    'subject_id' => $mention->subjectId,
                    'name_matched' => $mention->nameMatched,
                    'position' => $mention->position,
                    'count' => $mention->count,
                    'snippet' => $mention->snippet,
                ]);
            }

            $brandCited = false;

            foreach ($citations as $index => $citation) {
                $isBrand = Domains::matches($citation['url'], $brand->domains ?? []);
                $competitor = $isBrand ? null : $competitors->first(fn ($c) => Domains::matches($citation['url'], $c->domains ?? []));
                $brandCited = $brandCited || $isBrand;

                $result->citations()->create([
                    'url' => $citation['url'],
                    'domain' => $citation['domain'],
                    'title' => $citation['title'] ? mb_substr($citation['title'], 0, 255) : null,
                    'position' => $index + 1,
                    'is_brand' => $isBrand,
                    'competitor_id' => $competitor?->getKey(),
                    'category' => $this->categories->categorize($citation['url'], $brand, $isBrand, $competitor?->getKey()),
                ]);
            }

            // The status is set by RunProgress::finish(), which counts each result exactly once.
            $result->forceFill([
                'model' => $response->model,
                'answer' => $response->answer,
                'answer_hash' => hash('sha256', $response->answer),
                'brand_mentioned' => $brandMention !== null,
                'brand_position' => $brandMention?->position,
                'brand_mention_count' => $brandMention?->count ?? 0,
                'brand_cited' => $brandCited,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'searches' => $response->searches,
                'cost_usd' => $cost,
                'duration_ms' => $durationMs,
                'error' => null,
                'skip_reason' => null,
                'ran_at' => now(),
                'analysis_status' => app(Settings::class)->get('analysis.enabled', true) ? AnswerAnalyzer::PENDING : AnswerAnalyzer::OFF,
            ])->save();

            $result->prompt()->update(['last_run_at' => now()]);
        });

        $this->spend->record($result->engine, $response->model, Usage::PURPOSE_TRACKING, $response->inputTokens, $response->outputTokens, $response->searches, $cost, $brand);

        return $result;
    }
}
