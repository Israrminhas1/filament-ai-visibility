<?php

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use IsrarMinhas\FilamentAiVisibility\Competitors\CandidateActions;
use IsrarMinhas\FilamentAiVisibility\Competitors\CandidateNotOpen;
use IsrarMinhas\FilamentAiVisibility\Competitors\Classifier;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorIntelligence;
use IsrarMinhas\FilamentAiVisibility\Competitors\CompetitorSuggester;
use IsrarMinhas\FilamentAiVisibility\Competitors\Discovery;
use IsrarMinhas\FilamentAiVisibility\Competitors\EvidenceFetcher;
use IsrarMinhas\FilamentAiVisibility\Competitors\NameExtractor;
use IsrarMinhas\FilamentAiVisibility\Competitors\NoiseFilter;
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
use IsrarMinhas\FilamentAiVisibility\Jobs\ClassifyCandidatesJob;
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

/**
 * An OpenAI helper reply that is not JSON.
 */
function helperTextReply(string $text = 'Sorry, I cannot help with that.')
{
    return Http::response([
        'model' => 'gpt-5-mini',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        'usage' => ['input_tokens' => 500, 'output_tokens' => 10],
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
        [$test->p1, 'openai', 'Try Globex or Acme. Initech is fine. See https://globex.io, https://g2.com and https://bestcrm.net.', ['https://globex.io/crm', 'https://g2.com/crm', 'https://acme.com', 'https://bestcrm.net/list']],
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

    // Every website resolves to a public address; no real DNS lookups in tests.
    app()->instance(EvidenceFetcher::class, (new EvidenceFetcher)->resolveUsing(fn () => ['93.184.216.34']));
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
        Http::assertSent(fn ($request) => ! isset($request['tools']) && $request['model'] === 'claude-sonnet-5');

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

        expect($candidates->keys()->all())->toContain('Globex', 'Umbrella Corp', 'Hooli', 'bestcrm.net')
            // Review sites like G2 are not competitors.
            ->and($candidates->keys()->all())->not->toContain('google.com', 'acme.com', 'initech.com', 'Initech', 'g2.com')
            ->and($candidates['Globex']->domain)->toBe('globex.io')
            ->and($candidates['Globex']->answers)->toBe(2)
            ->and($candidates['Globex']->engines)->toEqualCanonicalizing(['openai', 'gemini'])
            ->and($candidates->keys()->first())->toBe('Globex')
            ->and($candidates['Hooli']->score)->toBeLessThan($candidates['Globex']->score);
    });

    it('keeps decisions when discovering again', function () {
        app(Discovery::class)->discover($this->brand);
        $list = Candidate::query()->where('domain', 'bestcrm.net')->first();
        app(CandidateActions::class)->ignore($list);

        app(Discovery::class)->discover($this->brand);

        expect($list->fresh()->status)->toBe(Candidate::STATUS_IGNORED);
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
        $g2 = Candidate::query()->where('domain', 'bestcrm.net')->first();

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
            ->and(Citation::query()->where('domain', 'bestcrm.net')->value('category'))->toBe('review_comparison');

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

describe('evidence fetching safety', function () {
    beforeEach(function () {
        $this->fetcher = (new EvidenceFetcher)->resolveUsing(fn (string $host) => [
            'internal.example.com' => ['10.0.0.5'],
            'metadata.example.com' => ['169.254.169.254'],
            'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
            'evil.example.com' => ['::1'],
        ][$host] ?? ['93.184.216.34']);
        app()->instance(EvidenceFetcher::class, $this->fetcher);
    });

    it('refuses private, loopback, link-local and reserved addresses', function (string $ip) {
        expect($this->fetcher->isPublicIp($ip))->toBeFalse();
    })->with(['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1', '::', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1']);

    it('allows public addresses', function () {
        expect($this->fetcher->isPublicIp('93.184.216.34'))->toBeTrue()
            ->and($this->fetcher->isPublicIp('2606:2800:220:1:248:1893:25c8:1946'))->toBeTrue();
    });

    it('refuses IP literals, odd hosts and hosts that resolve to private addresses', function (string $url) {
        expect($this->fetcher->safeAddress($url))->toBeNull();
    })->with([
        'http://127.0.0.1/', 'http://10.0.0.1/', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/',
        'http://2130706433/', 'http://0x7f.1/', 'http://localhost/', 'http://printer.local/', 'ftp://globex.io/',
        'https://globex.io:8080/', 'https://user:pass@globex.io/', 'https://internal.example.com/',
        'https://metadata.example.com/', 'https://mixed.example.com/', 'https://evil.example.com/',
    ]);

    it('accepts a public host', function () {
        expect($this->fetcher->safeAddress('https://globex.io/'))->toBe('93.184.216.34');
    });

    it('does not follow a redirect to a private address', function () {
        Http::fake(['globex.io/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        expect(app(EvidenceFetcher::class)->profile('globex.io')->status)->toBe('unreachable');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254'));

        Http::fake(['initech.com/*' => Http::response('', 301, ['Location' => 'https://internal.example.com/'])]);

        expect(app(EvidenceFetcher::class)->profile('initech.com')->status)->toBe('unreachable');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'internal.example.com'));
    });

    it('follows up to three safe redirects, checking each one', function () {
        Http::fake([
            'globex.io/*' => Http::response('', 301, ['Location' => 'https://globex-crm.com/home']),
            'globex-crm.com/*' => Http::response('<html><title>Globex</title><body>Hi</body></html>', 200, ['Content-Type' => 'text/html']),
            'loop.io/*' => Http::response('', 302, ['Location' => '/again']),
        ]);

        expect($this->fetcher->fetch('https://globex.io/'))->toContain('Globex')
            ->and($this->fetcher->fetch('https://loop.io/'))->toBeNull();

        Http::assertSentCount(2 + 1 + EvidenceFetcher::MAX_REDIRECTS);
    });

    it('reads no more than the size limit', function () {
        Http::fake([
            'big.io/*' => Http::response(str_repeat('a', EvidenceFetcher::MAX_BYTES + 5000), 200, ['Content-Type' => 'text/html']),
            'huge.io/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html', 'Content-Length' => (string) (EvidenceFetcher::MAX_BYTES * 10)]),
        ]);

        expect(strlen((string) $this->fetcher->fetch('https://big.io/')))->toBe(EvidenceFetcher::MAX_BYTES)
            ->and($this->fetcher->fetch('https://huge.io/'))->toBeNull();
    });
});

describe('discovery noise', function () {
    beforeEach(function () {
        $this->brand = $this->createBrand(['name' => 'Nintendo Co., Ltd.', 'domains' => ['nintendo.com']]);
        $prompt = $this->brand->prompts()->create(['text' => 'Best games console?']);
        $run = Run::query()->create(['brand_id' => $this->brand->id, 'results_total' => 1]);

        $result = Result::query()->create([
            'run_id' => $run->id, 'brand_id' => $this->brand->id, 'prompt_id' => $prompt->id, 'engine' => 'openai',
            'status' => ResultStatus::Success, 'answer' => 'The Nintendo Switch 2 and the Sony PlayStation 5.', 'ran_at' => now(),
        ]);

        foreach (['Nintendo Switch', 'Nintendo Switch 2', 'Nintendo Co', 'Sony', 'Reddit', 'YouTube', 'G2'] as $i => $name) {
            $result->mentions()->create(['subject_type' => 'entity', 'name_matched' => $name, 'position' => $i + 1, 'count' => 1]);
        }

        foreach (['nintendo.co.jp', 'store.nintendo.com', 'reddit.com', 'trustpilot.com', 'forbes.com', 'techradar.com', 'github.com', 'sony.com', 'gamestop.com', 'www.youtube.com'] as $i => $domain) {
            $result->citations()->create(['url' => "https://{$domain}/x", 'domain' => $domain, 'position' => $i + 1, 'category' => 'other', 'is_brand' => false]);
        }
    });

    it('never suggests the brand, its products or big platforms', function () {
        app(Discovery::class)->discover($this->brand);

        expect(Candidate::query()->pluck('name')->sort()->values()->all())->toBe(['Sony', 'gamestop.com'])
            ->and(Candidate::query()->where('name', 'Sony')->value('domain'))->toBe('sony.com');
    });

    it('strips legal endings when matching the brand', function () {
        expect(NoiseFilter::baseName('Nintendo Co., Ltd.'))->toBe('nintendo')
            ->and(NoiseFilter::baseName('Globex S.A.'))->toBe('globex')
            ->and(NoiseFilter::isOwnName($this->brand, 'Nintendo Switch 2'))->toBeTrue()
            ->and(NoiseFilter::isOwnName($this->brand, 'Nintendogs'))->toBeFalse()
            ->and(NoiseFilter::isOwnDomain($this->brand, 'nintendo.co.jp'))->toBeTrue()
            ->and(NoiseFilter::isOwnDomain($this->brand, 'sony.com'))->toBeFalse();
    });

    it('does not store the brand\'s own products as names', function () {
        $result = Result::query()->first();
        ResultMention::query()->delete();
        Http::fake(['api.openai.com/*' => helperReply(['answers' => [['id' => $result->id, 'names' => ['Nintendo Switch 2', 'Sony PlayStation 5']]]])]);

        app(NameExtractor::class)->extract($this->brand);

        expect(ResultMention::query()->pluck('name_matched')->all())->toBe(['Sony PlayStation 5']);
    });
});

describe('robustness', function () {
    beforeEach(fn () => seedCompetitorData($this));

    it('skips a batch with an invalid reply and carries on with the others', function () {
        config(['ai-visibility.discovery.extraction_batch' => 1]);
        $ids = Result::query()->orderByDesc('id')->pluck('id');

        Http::fakeSequence('api.openai.com/*')
            ->pushResponse(helperTextReply())
            ->pushResponse(helperReply(['answers' => [['id' => $ids[1], 'names' => ['Globex']]]]))
            ->pushResponse(helperReply(['answers' => [['id' => $ids[2], 'names' => ['Globex']]]]));

        expect(app(NameExtractor::class)->extract($this->brand))->toBe(2)
            ->and(Result::query()->whereNull('entities_extracted_at')->pluck('id')->all())->toBe([$ids[0]])
            ->and(ResultMention::query()->where('subject_type', 'entity')->count())->toBe(2);
    });

    it('gives up on an answer whose extraction keeps failing', function () {
        Http::fake(['api.openai.com/*' => helperTextReply()]);

        foreach (range(1, NameExtractor::MAX_ATTEMPTS) as $attempt) {
            app(NameExtractor::class)->extract($this->brand);
        }

        expect(Result::query()->whereNull('entities_extracted_at')->count())->toBe(0);
    });

    it('maps keys without the prefix and leaves unknown labels unclassified', function () {
        app(Discovery::class)->discover($this->brand);
        $globex = Candidate::query()->where('domain', 'globex.io')->first();
        $list = Candidate::query()->where('domain', 'bestcrm.net')->first();

        Http::fake([
            'api.openai.com/*' => helperReply(['results' => [
                ['key' => (string) $globex->id, 'label' => 'direct_competitor', 'confidence' => 'high'],
                ['key' => "c{$list->id}", 'label' => 'maybe_a_blog'],
            ]]),
            '*' => Http::response('', 404),
        ]);

        expect(app(Classifier::class)->classify($this->brand, collect([$globex, $list])))->toBe(1)
            ->and($globex->fresh()->label)->toBe(CompetitorLabel::DirectCompetitor)
            ->and($list->fresh()->label)->toBeNull()
            ->and($list->fresh()->status)->toBe(Candidate::STATUS_NEW)
            ->and($list->classifications()->count())->toBe(0);
    });

    it('does not pay again and again for candidates that fail to classify', function () {
        app(Discovery::class)->discover($this->brand);
        Http::fake(['api.openai.com/*' => helperTextReply(), '*' => Http::response('', 404)]);

        $due = app(Classifier::class)->due($this->brand);
        expect($due)->not->toBeEmpty()
            ->and(app(Classifier::class)->classify($this->brand))->toBe(0)
            ->and($due->first()->fresh()->status)->toBe(Candidate::STATUS_NEW)
            // Waits before trying again…
            ->and(app(Classifier::class)->due($this->brand))->toBeEmpty();

        // …and stops after a few attempts.
        foreach (range(2, Classifier::MAX_ATTEMPTS) as $attempt) {
            $this->travel(8)->days();
            expect(app(Classifier::class)->due($this->brand))->not->toBeEmpty();
            app(Classifier::class)->classify($this->brand);
        }

        $this->travel(8)->days();
        expect(app(Classifier::class)->due($this->brand))->toBeEmpty();
    });

    it('uses only the brand\'s own answers as evidence', function () {
        $other = $this->createBrand(['name' => 'Other', 'domains' => ['other.com']]);
        $prompt = $other->prompts()->create(['text' => 'Other?']);
        $otherRun = Run::query()->create(['brand_id' => $other->id, 'results_total' => 1]);
        $result = Result::query()->create([
            'run_id' => $otherRun->id, 'brand_id' => $other->id, 'prompt_id' => $prompt->id, 'engine' => 'openai',
            'status' => ResultStatus::Success, 'answer' => 'Globex secret plans.', 'ran_at' => now(),
        ]);
        $result->mentions()->create(['subject_type' => 'entity', 'name_matched' => 'Globex', 'position' => 1, 'count' => 1, 'snippet' => 'Globex secret plans.']);

        $ids = Result::query()->where('brand_id', $this->brand->id)->orderBy('id')->pluck('id');
        $candidateId = Candidate::query()->max('id') + 1;
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->pushResponse(helperReply(['answers' => [['id' => $ids[1], 'names' => ['Globex']]]]))
                ->pushResponse(helperReply(['results' => [['key' => "c{$candidateId}", 'label' => 'direct_competitor']]])),
            '*' => Http::response('', 404),
        ]);
        app(NameExtractor::class)->extract($this->brand);
        app(Discovery::class)->discover($this->brand);
        $globex = Candidate::query()->where('brand_id', $this->brand->id)->where('name', 'Globex')->first();
        expect($globex->id)->toBe($candidateId);

        app(Classifier::class)->classify($this->brand, collect([$globex]));

        expect($globex->latestClassification->evidence['mentions'])->toBe(['Globex is cheapest. Umbrella Corp too.']);
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['input'] ?? ''), 'secret'));
    });

    it('accepts a candidate only once', function () {
        app(Discovery::class)->discover($this->brand);
        $candidate = Candidate::query()->where('domain', 'globex.io')->first();
        $stale = Candidate::query()->find($candidate->id);

        $first = app(CandidateActions::class)->accept($candidate);
        $second = app(CandidateActions::class)->accept($stale);

        expect($second->id)->toBe($first->id)
            ->and($this->brand->competitors()->where('source', 'discovered')->count())->toBe(1);

        $list = Candidate::query()->where('domain', 'bestcrm.net')->first();
        app(CandidateActions::class)->ignore($list);

        expect(fn () => app(CandidateActions::class)->accept($list))->toThrow(CandidateNotOpen::class);
    });

    it('ignores the merged domain together with a name', function () {
        $ids = Result::query()->orderBy('id')->pluck('id');
        Http::fake(['api.openai.com/*' => helperReply(['answers' => [['id' => $ids[1], 'names' => ['Globex']]]])]);
        app(NameExtractor::class)->extract($this->brand);
        app(Discovery::class)->discover($this->brand);

        app(CandidateActions::class)->ignore(Candidate::query()->where('key', 'name:globex')->first());

        // The name no longer appears, so the domain would come back on its own.
        ResultMention::query()->where('name_matched', 'Globex')->delete();
        app(Discovery::class)->discover($this->brand);

        expect(Candidate::query()->where('key', 'domain:globex.io')->value('status'))->toBe(Candidate::STATUS_IGNORED);
    });

    it('discovers from every unprocessed answer, not only the run that queued it', function () {
        app(Settings::class)->set(['analysis' => ['enabled' => false]]);
        $first = Run::query()->first();
        $second = Run::query()->create(['brand_id' => $this->brand->id, 'results_total' => 1]);
        Result::query()->create([
            'run_id' => $second->id, 'brand_id' => $this->brand->id, 'prompt_id' => $this->p1->id, 'engine' => 'openai',
            'status' => ResultStatus::Success, 'answer' => 'Hooli is great.', 'ran_at' => now(),
        ]);

        Http::fake(['api.openai.com/*' => helperReply(['answers' => []]), '*' => Http::response('', 404)]);

        (new DiscoverCompetitorsJob($this->brand->id, $this->brand->tenant_id, $first->id))->handle();

        expect(Result::query()->whereNull('entities_extracted_at')->count())->toBe(0)
            ->and(new DiscoverCompetitorsJob($this->brand->id, null))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    });
});

it('classifies chosen candidates from the queue', function () {
    seedCompetitorData($this);
    app(Discovery::class)->discover($this->brand);
    $globex = Candidate::query()->where('domain', 'globex.io')->first();

    Http::fake([
        'api.openai.com/*' => helperReply(['results' => [['key' => "c{$globex->id}", 'label' => 'indirect_competitor', 'confidence' => 'medium']]]),
        '*' => Http::response('', 404),
    ]);

    app()->call([new ClassifyCandidatesJob($this->brand->id, $this->brand->tenant_id, [$globex->id]), 'handle']);

    expect($globex->fresh()->label)->toBe(CompetitorLabel::IndirectCompetitor);
});
