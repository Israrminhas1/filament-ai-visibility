<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSync;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Prompts\PromptGenerator;
use IsrarMinhas\FilamentAiVisibility\Prompts\PromptQualityGate;
use IsrarMinhas\FilamentAiVisibility\Prompts\TopicClusterer;
use IsrarMinhas\FilamentAiVisibility\Reports\Metrics;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

function helperJson(array ...$replies)
{
    $sequence = Http::sequence();

    foreach ($replies as $json) {
        $sequence->push([
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ]);
    }

    return $sequence;
}

function connect(string $type, array $credentials, array $config = [], $brand = null): Connection
{
    return Connection::query()->create([
        'brand_id' => $brand->id, 'type' => $type, 'name' => $type,
        'credentials' => $credentials, 'config' => $config, 'status' => Connection::CONNECTED,
    ]);
}

beforeEach(function () {
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com'], 'market' => 'UK', 'description' => 'CRM for agencies']);
});

describe('keyword sources', function () {
    it('stores credentials encrypted', function () {
        connect('serpapi', ['api_key' => 'serp-secret'], brand: $this->brand);

        expect(Connection::query()->toBase()->value('credentials'))->not->toContain('serp-secret')
            ->and(Connection::query()->first()->credential('api_key'))->toBe('serp-secret');
    });

    it('pulls People also ask questions for the brand keywords', function () {
        $this->brand->keywords()->create(['keyword' => 'crm for agencies', 'search_volume' => 900]);
        $this->brand->keywords()->create(['keyword' => 'acme pricing', 'search_volume' => 5000]);
        $connection = connect('serpapi', ['api_key' => 'k'], ['max_seeds' => 5], $this->brand);

        Http::fake(['serpapi.com/search.json*' => Http::response([
            'related_questions' => [['question' => 'What is the best CRM for a small agency?']],
            'related_searches' => [['query' => 'crm for creative agencies']],
        ])]);

        $counts = app(KeywordSync::class)->sync($connection);

        expect($counts)->toBe(['created' => 2, 'updated' => 0, 'skipped' => 0])
            ->and(Keyword::query()->where('source', 'serpapi_paa')->pluck('keyword')->sort()->values()->all())->toBe(['What is the best CRM for a small agency?', 'crm for creative agencies'])
            ->and($connection->fresh()->next_sync_at->isFuture())->toBeTrue();

        // Branded keywords are not used as seeds; the market sets Google's country.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['q'] === 'crm for agencies' && $request['gl'] === 'gb');
    });

    it('pulls keywords with search volume from DataForSEO', function () {
        $connection = connect('dataforseo', ['login' => 'me', 'password' => 'pw'], brand: $this->brand);

        Http::fake(['api.dataforseo.com/*' => Http::response(['status_code' => 20000, 'tasks' => [[
            'status_code' => 20000,
            'result' => [['keyword' => 'agency crm', 'search_volume' => 1300, 'competition' => 'HIGH'], ['keyword' => 'crm uk', 'search_volume' => 700]],
        ]]])]);

        app(KeywordSync::class)->sync($connection);

        expect(Keyword::query()->orderByDesc('search_volume')->pluck('search_volume', 'keyword')->all())->toBe(['agency crm' => 1300, 'crm uk' => 700]);
        Http::assertSent(fn ($request) => $request[0]['target'] === 'acme.com' && $request[0]['location_code'] === 2826 && $request->hasHeader('Authorization', 'Basic ' . base64_encode('me:pw')));
    });

    it('pulls Search Console queries with a service account', function () {
        // Windows PHP builds need their bundled openssl.cnf to generate keys.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';

        if (is_file($config)) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);

        $connection = connect('gsc', ['service_account' => json_encode(['client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => $pem])], ['property' => 'sc-domain:acme.com', 'min_impressions' => 10], $this->brand);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:acme.com']]]),
            'www.googleapis.com/webmasters/v3/sites/*' => Http::response(['rows' => [
                ['keys' => ['crm for agencies'], 'clicks' => 12, 'impressions' => 800, 'position' => 7.4],
                ['keys' => ['rare query'], 'clicks' => 0, 'impressions' => 3, 'position' => 40],
            ]]),
        ]);

        $source = app(KeywordSourceRegistry::class)->get('gsc');

        expect($source->test($connection)->ok)->toBeTrue();

        app(KeywordSync::class)->sync($connection);

        expect(Keyword::query()->pluck('impressions', 'keyword')->all())->toBe(['crm for agencies' => 800]);

        // The JWT assertion is signed with the service account key.
        Http::assertSent(function ($request) use ($key) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return false;
            }

            [$header, $payload, $signature] = explode('.', $request['assertion']);
            $details = openssl_pkey_get_details($key);

            return openssl_verify("{$header}.{$payload}", base64_decode(strtr($signature, '-_', '+/')), $details['key'], OPENSSL_ALGO_SHA256) === 1
                && json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['scope'] === 'https://www.googleapis.com/auth/webmasters.readonly';
        });
    });

    it('marks failing connections, alerts once, and retries tomorrow', function () {
        app(Settings::class)->set(['alerts' => ['slack_webhook' => 'https://hooks.slack.com/x']]);
        $this->brand->keywords()->create(['keyword' => 'crm']);
        $connection = connect('serpapi', ['api_key' => 'bad'], brand: $this->brand);

        Http::fake([
            'serpapi.com/*' => Http::response(['error' => 'Invalid API key.'], 401),
            'hooks.slack.com/*' => Http::response('ok'),
        ]);

        expect(fn () => app(KeywordSync::class)->sync($connection))->toThrow(SourceFailed::class);
        expect(fn () => app(KeywordSync::class)->sync($connection->fresh()))->toThrow(SourceFailed::class);

        $connection->refresh();

        expect($connection->status)->toBe(Connection::NEEDS_REAUTH)
            ->and($connection->last_error)->toContain('Invalid API key')
            ->and($connection->next_sync_at->isTomorrow())->toBeTrue();

        Http::assertSentCount(3);
    });

    it('respects the keyword limit but keeps updating existing keywords', function () {
        app(Settings::class)->set(['limits' => ['max_keywords_per_brand' => 2]]);
        $this->brand->keywords()->create(['keyword' => 'crm uk', 'search_volume' => 1]);
        $connection = connect('dataforseo', ['login' => 'a', 'password' => 'b'], brand: $this->brand);

        Http::fake(['api.dataforseo.com/*' => Http::response(['tasks' => [['status_code' => 20000, 'result' => [
            ['keyword' => 'crm uk', 'search_volume' => 700], ['keyword' => 'one', 'search_volume' => 5], ['keyword' => 'two', 'search_volume' => 5],
        ]]]])]);

        expect(app(KeywordSync::class)->sync($connection))->toBe(['created' => 1, 'updated' => 1, 'skipped' => 1])
            ->and(Keyword::query()->where('keyword', 'crm uk')->value('search_volume'))->toBe(700);
    });

    it('syncs due connections from the command', function () {
        $this->brand->keywords()->create(['keyword' => 'crm']);
        connect('serpapi', ['api_key' => 'k'], brand: $this->brand)->forceFill(['next_sync_at' => now()->subHour()])->save();
        connect('serpapi', ['api_key' => 'k'], brand: $this->brand)->forceFill(['next_sync_at' => now()->addWeek()])->save();
        Http::fake(['serpapi.com/*' => Http::response(['related_questions' => []])]);

        $this->artisan('ai-visibility:sync-keywords')->assertSuccessful();

        Http::assertSentCount(1);
    });
});

describe('prompt generation', function () {
    beforeEach(fn () => app(KeyResolver::class)->store('openai', 'sk'));

    it('rejects weak prompts with rules before any AI review', function () {
        $gate = app(PromptQualityGate::class);
        $existing = ['What is the best CRM for small marketing agencies?'];

        expect($gate->check('CRM?', $this->brand, PromptIntent::Discovery, []))->toContain('Too short')
            ->and($gate->check('Our agency has used spreadsheets for years and the whole team finds them slow to update every single week', $this->brand, PromptIntent::Discovery, []))->toContain('Not a question')
            ->and($gate->check('Is Acme good for agencies?', $this->brand, PromptIntent::Discovery, []))->toContain('Names the brand')
            ->and($gate->check('Is Acme good for agencies?', $this->brand, PromptIntent::Branded, []))->toBeNull()
            ->and($gate->check('What is the best CRM for small marketing agencies', $this->brand, PromptIntent::Discovery, $existing))->toContain('Duplicate')
            ->and($gate->check('Best CRM tools for agencies with remote teams?', $this->brand, PromptIntent::Discovery, $existing))->toBeNull();
    });

    it('generates prompts from keywords, reviews them, and saves suggestions', function () {
        $keyword = $this->brand->keywords()->create(['keyword' => 'crm for agencies', 'search_volume' => 900]);

        Http::fake(['api.openai.com/*' => helperJson(
            ['prompts' => [
                ['text' => 'What is the best CRM for a 10-person creative agency?', 'intent' => 'discovery', 'keyword' => 'CRM for agencies'],
                ['text' => 'Which CRM do agencies switch to from HubSpot?', 'intent' => 'alternatives', 'keyword' => null],
                ['text' => 'Is Acme worth it?', 'intent' => 'discovery'],
                ['text' => 'Tell me about CRMs', 'intent' => 'discovery'],
            ]],
            ['reviews' => [
                ['id' => 0, 'score' => 5, 'reason' => 'Very realistic'],
                ['id' => 1, 'score' => 3, 'reason' => 'A bit vague'],
                ['id' => 3, 'score' => 2, 'reason' => 'Too broad'],
            ]],
        )]);

        $result = app(PromptGenerator::class)->generate($this->brand, count: 4);

        expect($result['suggested']->pluck('text')->all())->toBe(['What is the best CRM for a 10-person creative agency?'])
            ->and($result['rejected']->count())->toBe(3);

        $suggested = Prompt::query()->where('status', PromptStatus::Suggested)->first();
        $vague = Prompt::query()->where('text', 'like', 'Which CRM%')->first();

        expect($suggested->source)->toBe(PromptSource::Generated)
            ->and($suggested->quality_score)->toBe(5)
            ->and($suggested->keywords->pluck('id')->all())->toBe([$keyword->id])
            ->and($vague->status)->toBe(PromptStatus::Rejected)
            ->and($vague->quality_reason)->toContain('Low quality (3/5)')
            ->and(Prompt::query()->where('text', 'Is Acme worth it?')->value('quality_reason'))->toContain('Names the brand');

        // The generator is grounded in the keyword and told not to repeat existing prompts.
        Http::assertSent(fn ($request) => str_contains((string) $request['input'], 'crm for agencies (900 searches/month)'));
    });

    it('can skip the AI review and return candidates without saving', function () {
        app(Settings::class)->set(['generation' => ['ai_review' => false]]);
        Http::fake(['api.openai.com/*' => helperJson(['prompts' => [['text' => 'Which CRM is easiest for agencies to set up?', 'intent' => 'discovery']]])]);

        $result = app(PromptGenerator::class)->generate($this->brand, save: false);

        expect($result['candidates']->where('passed', true)->pluck('text')->all())->toBe(['Which CRM is easiest for agencies to set up?'])
            ->and(Prompt::query()->count())->toBe(0);
        Http::assertSentCount(1);
    });

    it('proposes topics and applies them', function () {
        $a = $this->brand->prompts()->create(['text' => 'Cheapest CRM for agencies?']);
        $b = $this->brand->prompts()->create(['text' => 'CRM with Slack integration?']);
        $other = $this->createBrand(['name' => 'Other', 'domains' => ['other.com']])->prompts()->create(['text' => 'Not this brand?']);

        Http::fake(['api.openai.com/*' => helperJson(['topics' => [
            ['name' => 'Pricing', 'prompt_ids' => [$a->id, $other->id]],
            ['name' => 'Integrations', 'prompt_ids' => [$b->id, $a->id]],
            ['name' => 'Empty', 'prompt_ids' => []],
        ]])]);

        $proposal = app(TopicClusterer::class)->propose($this->brand);

        expect($proposal)->toBe([
            ['name' => 'Pricing', 'description' => null, 'prompt_ids' => [$a->id]],
            ['name' => 'Integrations', 'description' => null, 'prompt_ids' => [$b->id]],
        ]);

        expect(app(TopicClusterer::class)->apply($this->brand, $proposal))->toBe(2)
            ->and($a->fresh()->topic->name)->toBe('Pricing')
            ->and($other->fresh()->topic_id)->toBeNull();
    });
});

describe('reports', function () {
    it('weights visibility by search demand and reports per topic', function () {
        $pricing = $this->brand->topics()->create(['name' => 'Pricing']);
        $popular = $this->brand->prompts()->create(['text' => 'Best CRM?', 'topic_id' => $pricing->id]);
        $rare = $this->brand->prompts()->create(['text' => 'Niche CRM?']);
        $popular->keywords()->attach($this->brand->keywords()->create(['keyword' => 'best crm', 'search_volume' => 900]));
        $rare->keywords()->attach($this->brand->keywords()->create(['keyword' => 'niche crm', 'impressions' => 100]));

        $run = Run::query()->create(['brand_id' => $this->brand->id, 'results_total' => 2]);

        foreach ([[$popular, false], [$rare, true]] as [$prompt, $mentioned]) {
            Result::query()->create(['run_id' => $run->id, 'brand_id' => $this->brand->id, 'prompt_id' => $prompt->id, 'engine' => 'openai', 'status' => ResultStatus::Success, 'brand_mentioned' => $mentioned, 'ran_at' => now()]);
        }

        $filters = ReportFilters::fromState(['brand' => $this->brand->id]);

        // 50% plain visibility, but only 10% of search demand.
        expect(app(Metrics::class)->summary($filters)['visibility'])->toBe(50.0)
            ->and(app(Metrics::class)->reach($filters))->toBe(['reach' => 10.0, 'demand' => 1000, 'prompts' => 2])
            ->and(app(Metrics::class)->topics($filters)->first())->toMatchArray(['name' => 'Pricing', 'prompts' => 1, 'answers' => 1, 'visibility' => 0.0]);
    });
});
