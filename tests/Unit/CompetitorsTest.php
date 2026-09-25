<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use IsrarMinhas\FilamentAiVisibility\Competitors\CandidateActions;
use IsrarMinhas\FilamentAiVisibility\Competitors\Classifier;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorSuggester;
use IsrarMinhas\FilamentAiVisibility\Competitors\Discovery;
use IsrarMinhas\FilamentAiVisibility\Competitors\EvidenceFetcher;
use IsrarMinhas\FilamentAiVisibility\Competitors\NameExtractor;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Events\RunCompleted;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;
use IsrarMinhas\FilamentAiVisibility\Jobs\DiscoverCompetitorsJob;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Citation;
use IsrarMinhas\FilamentAiVisibility\Models\DomainProfile;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\ResultMention;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Reports\Metrics;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

/**
 * An OpenAI helper reply containing the given JSON.
 */
function helperReply(array $json)
{
    return Http::response([
        'model' => 'gpt-5-mini',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json), 'annotations' => []]]]],
        'usage' => ['input_tokens' => 500, 'output_tokens' => 100],
    ]);
}

function seedCompetitorData($test): void
{
    $test->brand = $test->createBrand(['name' => 'Acme', 'domains' => ['acme.com'], 'description' => 'CRM for agencies']);
    $test->known = $test->brand->competitors()->create(['name' => 'Initech', 'domains' => ['initech.com']]);
    $test->p1 = $test->brand->prompts()->create(['text' => 'Best CRM?']);
    $test->p2 = $test->brand->prompts()->create(['text' => 'Cheap CRM?']);
    $run = Run::query()->create(['brand_id' => $test->brand->id, 'results_total' => 3]);

    $answers = [
        [$test->p1, 'openai', 'Try Globex or Acme. Initech is fine. See https://globex.io and https://g2.com.', ['https://globex.io/crm', 'https://g2.com/crm', 'https://acme.com']],
        [$test->p2, 'gemini', 'Globex is cheapest. Umbrella Corp too.', ['https://globex.io', 'https://google.com/search']],
        [$test->p2, 'openai', 'Umbrella Corp and Hooli.', ['https://initech.com']],
    ];

    foreach ($answers as [$prompt, $engine, $text, $urls]) {
        $result = Result::query()->create([
            'run_id' => $run->id, 'brand_id' => $test->brand->id, 'prompt_id' => $prompt->id, 'engine' => $engine,
            'status' => ResultStatus::Success, 'answer' => $text, 'ran_at' => now(),
        ]);

        foreach ($urls as $i => $url) {
            $domain = parse_url($url, PHP_URL_HOST);
            $result->citations()->create([
                'url' => $url, 'domain' => $domain, 'position' => $i + 1, 'category' => 'other',
                'is_brand' => $domain === 'acme.com', 'competitor_id' => $domain === 'initech.com' ? $test->known->id : null,
            ]);
        }
    }
}

beforeEach(function () {
    app(KeyResolver::class)->store('openai', 'sk');
});

describe('helper calls', function () {
    it('parses JSON leniently', function () {
        expect((new CompletionResponse("```json\n{\"a\": 1}\n```", 'm'))->json())->toBe(['a' => 1])
            ->and((new CompletionResponse('Sure! {"a": [1]} Hope that helps.', 'm'))->json())->toBe(['a' => [1]])
            ->and((new CompletionResponse('no json here', 'm'))->json())->toBeNull();
    });

    it('calls the helper engine without web search and records the cost', function () {
        Http::fake(['api.openai.com/*' => helperReply(['ok' => true])]);

        expect(app(HelperAi::class)->json('classification', 'Classify'))->toBe(['ok' => true]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses') && ! isset($request['tools']));
        expect(Usage::query()->where('purpose', 'classification')->count())->toBe(1);
    });

    it('uses JSON mode on Gemini and no tools on Claude', function () {
        app(KeyResolver::class)->remove('openai');
        app(KeyResolver::class)->store('anthropic', 'a');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"x":1}']], 'usage' => []])]);

        expect(app(HelperAi::class)->json('analysis', 'Hi'))->toBe(['x' => 1]);
        Http::assertSent(fn ($request) => ! isset($request['tools']) && $request['model'] === 'claude-haiku-4-5');

        app(KeyResolver::class)->remove('anthropic');
        app(KeyResolver::class)->store('gemini', 'g');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"y":2}']]]]]])]);

        expect(app(HelperAi::class)->json('analysis', 'Hi'))->toBe(['y' => 2]);
        Http::assertSent(fn ($request) => ($request['generationConfig']['responseMimeType'] ?? null) === 'application/json');
    });

    it('pauses the helper engine on a bad key and explains', function () {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        expect(fn () => app(HelperAi::class)->json('analysis', 'Hi'))->toThrow(HelperUnavailable::class, 'was paused');
        expect(app(EngineManager::class)->state('openai')->reason)->toBe(PauseReason::InvalidKey);
    });

    it('refuses when no engine, the kill switch or the budget prevents it', function () {
        app(Settings::class)->setKillSwitch(true);
        expect(fn () => app(HelperAi::class)->json('analysis', 'Hi'))->toThrow(HelperUnavailable::class, 'Pause everything');

        app(Settings::class)->setKillSwitch(false);
        app(KeyResolver::class)->remove('openai');
        expect(fn () => app(HelperAi::class)->json('analysis', 'Hi'))->toThrow(HelperUnavailable::class, 'No AI engine');
    });
});

describe('discovery', function () {
    beforeEach(fn () => seedCompetitorData($this));

    it('extracts names mentioned in answers, skipping tracked brands and invented names', function () {
        $ids = Result::query()->orderBy('id')->pluck('id');

        Http::fake(['api.openai.com/*' => helperReply(['answers' => [
            ['id' => $ids[0], 'names' => ['Globex', 'Acme', 'Initech', 'Not In Answer']],
            ['id' => $ids[1], 'names' => ['Globex', 'Umbrella Corp']],
            ['id' => $ids[2], 'names' => ['Umbrella Corp', 'Hooli', 'hooli']],
        ]])]);

        expect(app(NameExtractor::class)->extract($this->brand))->toBe(3);

        expect(ResultMention::query()->where('subject_type', 'entity')->pluck('name_matched')->sort()->values()->all())
            ->toBe(['Globex', 'Globex', 'Hooli', 'Umbrella Corp', 'Umbrella Corp'])
            ->and(Result::query()->whereNull('entities_extracted_at')->count())->toBe(0)
            ->and(ResultMention::query()->where('name_matched', 'Hooli')->value('snippet'))->toBe('Umbrella Corp and Hooli.');

        // Already processed answers are not sent again.
        Http::fake();
        expect(app(NameExtractor::class)->extract($this->brand))->toBe(0);
    });

    it('finds, merges and scores candidates, excluding the brand, known competitors and search engines', function () {
        $ids = Result::query()->orderBy('id')->pluck('id');
        Http::fake(['api.openai.com/*' => helperReply(['answers' => [
            ['id' => $ids[0], 'names' => ['Globex']],
            ['id' => $ids[1], 'names' => ['Globex', 'Umbrella Corp']],
            ['id' => $ids[2], 'names' => ['Umbrella Corp', 'Hooli']],
        ]])]);
        app(NameExtractor::class)->extract($this->brand);

        app(Discovery::class)->discover($this->brand);

        $candidates = Candidate::query()->orderByDesc('score')->get()->keyBy('name');

        expect($candidates->keys()->all())->toContain('Globex', 'Umbrella Corp', 'Hooli', 'g2.com')
            ->and($candidates->keys()->all())->not->toContain('google.com', 'acme.com', 'initech.com', 'Initech')
            ->and($candidates['Globex']->domain)->toBe('globex.io')
            ->and($candidates['Globex']->answers)->toBe(2)
            ->and($candidates['Globex']->engines)->toEqualCanonicalizing(['openai', 'gemini'])
            ->and($candidates->keys()->first())->toBe('Globex')
            ->and($candidates['Hooli']->score)->toBeLessThan($candidates['Globex']->score);
    });

    it('keeps decisions when discovering again', function () {
        app(Discovery::class)->discover($this->brand);
        $g2 = Candidate::query()->where('domain', 'g2.com')->first();
        app(CandidateActions::class)->ignore($g2);

        app(Discovery::class)->discover($this->brand);

        expect($g2->fresh()->status)->toBe(Candidate::STATUS_IGNORED);
    });

    it('fetches website evidence once and reuses it', function () {
        Http::fake(['globex.io/*' => Http::response('<html><head><title>Globex CRM</title><meta name="description" content="CRM for small teams"></head><body><nav>Menu</nav><h1>The CRM agencies love</h1><p>' . str_repeat('Globex helps agencies manage clients. ', 20) . '</p><script>x()</script></body></html>', 200, ['Content-Type' => 'text/html'])]);

        $profile = app(EvidenceFetcher::class)->profile('globex.io');
        app(EvidenceFetcher::class)->profile('globex.io');

        expect($profile->title)->toBe('Globex CRM')
            ->and($profile->description)->toBe('CRM for small teams')
            ->and($profile->headings)->toBe(['The CRM agencies love'])
            ->and($profile->excerpt)->toContain('Globex helps agencies')
            ->and($profile->excerpt)->not->toContain('x()')
            ->and($profile->excerpt)->not->toContain('Menu');

        Http::assertSentCount(1);
    });

    it('classifies top candidates with evidence and learns from corrections', function () {
        app(Discovery::class)->discover($this->brand);
        $globex = Candidate::query()->where('domain', 'globex.io')->first();
        $g2 = Candidate::query()->where('domain', 'g2.com')->first();

        // A past correction for this brand.
        app(CandidateActions::class)->relabel($g2, CompetitorLabel::ReviewComparison);
        Candidate::query()->whereKey($g2->id)->update(['status' => 'new']);

        Http::fake([
            'api.openai.com/*' => helperReply(['results' => [
                ['key' => "c{$globex->id}", 'label' => 'direct_competitor', 'confidence' => 'high', 'company_name' => 'Globex Inc', 'offering_summary' => 'CRM for teams', 'reason' => 'Same product'],
                ['key' => "c{$g2->id}", 'label' => 'review_comparison', 'confidence' => 'high', 'company_name' => 'G2', 'reason' => 'Review site'],
                ['key' => 'c999999', 'label' => 'direct_competitor'],
            ]]),
            '*' => Http::response('<html><title>Site</title></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $count = app(Classifier::class)->classify($this->brand, Candidate::query()->whereKey([$globex->id, $g2->id])->get());

        expect($count)->toBe(2)
            ->and($globex->fresh()->label)->toBe(CompetitorLabel::DirectCompetitor)
            ->and($globex->fresh()->status)->toBe(Candidate::STATUS_CLASSIFIED)
            ->and($globex->latestClassification->company_name)->toBe('Globex Inc')
            ->and($globex->latestClassification->evidence['website']['title'])->toBe('Site')
            // The review-site label categorises its past citations.
            ->and(Citation::query()->where('domain', 'g2.com')->value('category'))->toBe('review_comparison');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'openai.com')
            && str_contains((string) $request['input'], 'CORRECTIONS FROM THE USER')
            && str_contains((string) $request['input'], "key: c{$globex->id}"));
    });

    it('can accept high-confidence direct competitors automatically', function () {
        app(Settings::class)->set(['discovery' => ['auto_accept' => true]]);
        app(Discovery::class)->discover($this->brand);
        $globex = Candidate::query()->where('domain', 'globex.io')->first();

        Http::fake([
            'api.openai.com/*' => helperReply(['results' => [['key' => "c{$globex->id}", 'label' => 'direct_competitor', 'confidence' => 'high', 'company_name' => 'Globex']]]),
            '*' => Http::response('', 404),
        ]);

        app(Classifier::class)->classify($this->brand, collect([$globex]));

        expect($globex->fresh()->status)->toBe(Candidate::STATUS_ACCEPTED)
            ->and($this->brand->competitors()->pluck('name')->all())->toContain('Globex');
    });

    it('tracks an accepted candidate and updates past answers', function () {
        $ids = Result::query()->orderBy('id')->pluck('id');
        Http::fake(['api.openai.com/*' => helperReply(['answers' => [['id' => $ids[1], 'names' => ['Globex']]]])]);
        app(NameExtractor::class)->extract($this->brand);
        app(Discovery::class)->discover($this->brand);

        $competitor = app(CandidateActions::class)->accept(Candidate::query()->where('name', 'Globex')->first());

        expect($competitor->domains)->toBe(['globex.io'])
            ->and($competitor->source)->toBe('discovered')
            ->and(Citation::query()->where('domain', 'globex.io')->pluck('competitor_id')->unique()->all())->toBe([$competitor->id])
            ->and(ResultMention::query()->where('subject_type', 'competitor')->where('subject_id', $competitor->id)->count())->toBe(1);

        $sov = app(Metrics::class)->shareOfVoice(ReportFilters::fromState(['brand' => $this->brand->id]));
        expect($sov->pluck('name')->all())->toContain('Globex');
    });

    it('respects the competitor limit when accepting', function () {
        app(Settings::class)->set(['limits' => ['max_competitors_per_brand' => 1]]);
        app(Discovery::class)->discover($this->brand);

        expect(fn () => app(CandidateActions::class)->accept(Candidate::query()->first()))->toThrow(LimitExceeded::class);
    });

    it('runs the whole pipeline, and still discovers without an AI helper', function () {
        app(KeyResolver::class)->remove('openai');

        $report = app(CompetitorIntelligence::class)->run($this->brand);

        expect($report['candidates'])->toBeGreaterThan(0)
            ->and($report['classified'])->toBe(0)
            ->and($report['skipped'])->toContain('No AI engine');
    });

    it('queues discovery after each run with answers', function () {
        Queue::fake();
        $run = Run::query()->first();
        $run->forceFill(['results_done' => 3, 'status' => RunStatus::Completed])->save();

        RunCompleted::dispatch($run);

        Queue::assertPushed(DiscoverCompetitorsJob::class, fn ($job) => $job->brandId === $this->brand->id && $job->runId === $run->id);
    });

    it('suggests competitors for a new brand, skipping ones already listed', function () {
        Http::fake(['api.openai.com/*' => helperReply(['competitors' => [
            ['name' => 'Globex', 'domain' => 'https://www.globex.io', 'reason' => 'Same market'],
            ['name' => 'Initech', 'domain' => 'initech.com'],
            ['name' => 'Acme', 'domain' => 'acme.com'],
            ['name' => ''],
        ]])]);

        $suggestions = app(CompetitorSuggester::class)->suggest($this->brand, ['Initech']);

        expect($suggestions)->toBe([['name' => 'Globex', 'domain' => 'globex.io', 'reason' => 'Same market']]);
    });
});

it('runs from the discover command', function () {
    seedCompetitorData($this);
    app(KeyResolver::class)->remove('openai');

    $this->artisan('ai-visibility:discover')->assertSuccessful();

    expect(Candidate::query()->count())->toBeGreaterThan(0)
        ->and(DomainProfile::query()->count())->toBe(0);
});
