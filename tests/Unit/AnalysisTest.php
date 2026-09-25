<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Analysis\AnswerAnalyzer;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Reports\CompetitorMetrics;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

function openAiJson(array $json)
{
    return Http::response([
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
    ]);
}

/**
 * [prompt, engine, [[type, subjectId, position, sentiment, recommendation, descriptors]], [[domain, competitorId|null]]]
 */
function analysedAnswer($test, $prompt, string $engine, array $mentions, array $citations = [], string $analysis = 'done'): Result
{
    $test->run ??= Run::query()->create(['brand_id' => $test->brand->id, 'results_total' => 0]);

    $brandMention = collect($mentions)->firstWhere(0, 'brand');

    $result = Result::query()->create([
        'run_id' => $test->run->id, 'brand_id' => $test->brand->id, 'prompt_id' => $prompt->id, 'engine' => $engine,
        'status' => ResultStatus::Success, 'answer' => 'Acme and Globex are options. Hooli too.', 'ran_at' => now(),
        'brand_mentioned' => (bool) $brandMention, 'brand_position' => $brandMention[2] ?? null,
        'brand_cited' => collect($citations)->contains(fn ($c) => $c[0] === 'acme.com'),
        'analysis_status' => $analysis,
    ]);

    foreach ($mentions as [$type, $id, $position, $sentiment, $recommendation, $descriptors]) {
        $result->mentions()->create([
            'subject_type' => $type, 'subject_id' => $id, 'name_matched' => 'x', 'position' => $position,
            'sentiment' => $sentiment, 'recommendation' => $recommendation, 'descriptors' => $descriptors,
        ]);
    }

    foreach ($citations as $i => [$domain, $competitorId]) {
        $result->citations()->create([
            'url' => "https://{$domain}/", 'domain' => $domain, 'position' => $i + 1, 'category' => $domain === 'g2.com' ? 'review_comparison' : 'other',
            'is_brand' => $domain === 'acme.com', 'competitor_id' => $competitorId,
        ]);
    }

    return $result;
}

beforeEach(function () {
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->globex = $this->brand->competitors()->create(['name' => 'Globex', 'domains' => ['globex.io']]);
    $this->initech = $this->brand->competitors()->create(['name' => 'Initech']);
    $this->p1 = $this->brand->prompts()->create(['text' => 'Best CRM?']);
    $this->p2 = $this->brand->prompts()->create(['text' => 'Cheap CRM?']);
    $this->p3 = $this->brand->prompts()->create(['text' => 'CRM for agencies?']);
});

describe('answer analysis', function () {
    it('adds sentiment, recommendation and descriptors to detected mentions, and collects other names', function () {
        app(KeyResolver::class)->store('openai', 'sk');
        $result = analysedAnswer($this, $this->p1, 'openai', [['brand', $this->brand->id, 1, null, null, null], ['competitor', $this->globex->id, 2, null, null, null]], analysis: 'pending');

        Http::fake(['api.openai.com/*' => openAiJson(['answers' => [[
            'id' => $result->id,
            'mentions' => [
                ['name' => 'acme', 'sentiment' => 'positive', 'score' => 0.8, 'recommendation' => 'top_pick', 'descriptors' => ['Easy to use', 'affordable', 'easy to use']],
                ['name' => 'Globex', 'sentiment' => 'negative', 'score' => -3, 'recommendation' => 'cautioned', 'descriptors' => []],
                ['name' => 'Initech', 'sentiment' => 'positive'],
                ['name' => 'Unknown Co', 'sentiment' => 'positive'],
            ],
            'other_names' => ['Hooli', 'Acme', 'Not There'],
        ]]])]);

        expect(app(AnswerAnalyzer::class)->analyze($this->brand))->toBe(1);

        $result->refresh();
        $brand = $result->mentions->firstWhere('subject_type', 'brand');
        $globex = $result->mentions->where('subject_type', 'competitor')->firstWhere('subject_id', $this->globex->id);

        expect($result->analysis_status)->toBe('done')
            ->and($result->brand_sentiment)->toBe('positive')
            ->and($result->brand_recommendation)->toBe('top_pick')
            ->and($brand->sentiment_score)->toBe(0.8)
            ->and($brand->descriptors)->toBe(['Easy to use', 'affordable', 'easy to use'])
            ->and($globex->sentiment_score)->toBe(-1.0)
            ->and($globex->recommendation)->toBe('cautioned')
            // Initech wasn't detected in this answer, so it gets no mention.
            ->and($result->mentions->where('subject_type', 'competitor')->where('subject_id', $this->initech->id)->count())->toBe(0)
            ->and(ResultMention::query()->where('subject_type', 'entity')->pluck('name_matched')->all())->toBe(['Hooli'])
            ->and($result->entities_extracted_at)->not->toBeNull();
    });

    it('defers answers when no helper is available, and catches up later', function () {
        $result = analysedAnswer($this, $this->p1, 'openai', [['brand', $this->brand->id, 1, null, null, null]], analysis: 'pending');

        expect(fn () => app(AnswerAnalyzer::class)->analyze($this->brand))->toThrow(HelperUnavailable::class);
        expect($result->fresh()->analysis_status)->toBe('deferred');

        app(KeyResolver::class)->store('openai', 'sk');
        Http::fake(['api.openai.com/*' => openAiJson(['answers' => [['id' => $result->id, 'mentions' => [['name' => 'Acme', 'sentiment' => 'neutral', 'recommendation' => 'listed']]]]])]);

        expect(app(AnswerAnalyzer::class)->analyze($this->brand))->toBe(1)
            ->and($result->fresh()->analysis_status)->toBe('done');
    });

    it('runs analysis in the pipeline without a separate extraction call', function () {
        app(KeyResolver::class)->store('openai', 'sk');
        app(Settings::class)->set(['discovery' => ['classify' => false]]);
        $result = analysedAnswer($this, $this->p1, 'openai', [], analysis: 'pending');

        Http::fake(['api.openai.com/*' => openAiJson(['answers' => [['id' => $result->id, 'mentions' => [], 'other_names' => ['Hooli']]]])]);

        $report = app(CompetitorIntelligence::class)->run($this->brand);

        expect($report['analyzed'])->toBe(1)
            ->and($report['extracted'])->toBe(0);
        Http::assertSentCount(1);
    });

    it('marks new answers for analysis only when it is on', function () {
        expect(app(Settings::class)->get('analysis.enabled'))->toBeTrue();

        app(Settings::class)->set(['analysis' => ['enabled' => false]]);
        expect(app(Settings::class)->get('analysis.enabled'))->toBeFalse();
    });
});

describe('competitor metrics', function () {
    beforeEach(function () {
        $b = $this->brand->id;
        $g = $this->globex->id;
        $i = $this->initech->id;

        // p1/openai: Acme first (top pick), Globex second.
        analysedAnswer($this, $this->p1, 'openai', [['brand', $b, 1, 'positive', 'top_pick', ['affordable']], ['competitor', $g, 2, 'neutral', 'listed', ['popular']]], [['acme.com', null], ['g2.com', null]]);
        // p1/gemini: Globex first, Acme second.
        analysedAnswer($this, $this->p1, 'gemini', [['competitor', $g, 1, 'positive', 'top_pick', ['popular']], ['brand', $b, 2, 'negative', 'cautioned', ['expensive']]], [['globex.io', $g]]);
        // p2/openai: only Globex and Initech — a gap for Acme.
        analysedAnswer($this, $this->p2, 'openai', [['competitor', $g, 1, 'positive', 'recommended', []], ['competitor', $i, 2, 'neutral', 'listed', []]], [['g2.com', null], ['reddit.com', null]]);
        // p3/gemini: nobody tracked.
        analysedAnswer($this, $this->p3, 'gemini', []);

        $this->filters = ReportFilters::fromState(['brand' => $this->brand->id]);
    });

    it('builds a leaderboard', function () {
        $rows = app(CompetitorMetrics::class)->leaderboard($this->filters)->keyBy('name');

        expect($rows['Globex'])->toMatchArray(['answers' => 3, 'visibility' => 75.0, 'share_of_voice' => 50.0, 'avg_position' => 1.3, 'win_rate' => 66.7, 'citation_rate' => 25.0, 'net_sentiment' => 67, 'top_pick_rate' => 33.3])
            ->and($rows['Acme'])->toMatchArray(['answers' => 2, 'visibility' => 50.0, 'share_of_voice' => 33.3, 'avg_position' => 1.5, 'win_rate' => 50.0, 'citation_rate' => 25.0, 'net_sentiment' => 0])
            ->and($rows['Initech'])->toMatchArray(['answers' => 1, 'visibility' => 25.0])
            ->and($rows->keys()->first())->toBe('Globex');
    });

    it('builds an engine heatmap', function () {
        $heatmap = app(CompetitorMetrics::class)->heatmap($this->filters);
        $rows = $heatmap['rows']->keyBy('name');

        expect($heatmap['engines'])->toBe(['gemini', 'openai'])
            ->and($rows['Acme']['cells'])->toBe(['gemini' => 50.0, 'openai' => 50.0])
            ->and($rows['Globex']['cells'])->toBe(['gemini' => 50.0, 'openai' => 100.0]);
    });

    it('summarises perception', function () {
        $perception = app(CompetitorMetrics::class)->perception($this->filters);

        expect($perception['analysed'])->toBe(2)
            ->and($perception['sentiment'])->toBe(['positive' => 1, 'neutral' => 0, 'negative' => 1])
            ->and($perception['recommendation']['top_pick'])->toBe(1)
            ->and(array_keys($perception['descriptors']))->toEqualCanonicalizing(['affordable', 'expensive']);
    });

    it('compares the brand with one competitor', function () {
        $h2h = app(CompetitorMetrics::class)->headToHead($this->filters, $this->globex);

        expect($h2h['brand'])->toMatchArray(['answers' => 2, 'wins' => 1])
            ->and($h2h['rival'])->toMatchArray(['answers' => 3, 'wins' => 2])
            ->and($h2h['co_mention_rate'])->toBe(66.7)
            ->and($h2h['rival_prompts']->pluck('text')->all())->toBe(['Cheap CRM?'])
            ->and($h2h['brand_prompts']->all())->toBe([])
            // globex.io and g2.com are also cited in answers naming Acme, so only reddit.com is a gap.
            ->and($h2h['rival_only_sources'])->toBe(['reddit.com' => 1]);
    });

    it('finds opportunities where competitors appear without the brand', function () {
        $opportunities = app(CompetitorMetrics::class)->opportunities($this->filters);

        expect($opportunities['missed_answers'])->toBe(1)
            ->and($opportunities['prompts']->first())->toMatchArray(['text' => 'Cheap CRM?', 'answers' => 1, 'competitors' => ['Globex', 'Initech'], 'engines' => ['openai'], 'score' => 2])
            ->and($opportunities['sources']->pluck('domain')->all())->toEqualCanonicalizing(['g2.com', 'reddit.com']);
    });
});
