<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\SerpApiGoogleEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Models\ProviderKey;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

function askGoogle(string $engine, ?string $country = 'GB')
{
    return app(EngineRegistry::class)->get($engine)->ask(new EngineRequest('Best CRM for agencies?', $engine, 'serp-key', $country));
}

it('reads Google AI Overviews with headings, lists and sources', function () {
    Http::fake(['serpapi.com/search.json*' => Http::response([
        'ai_overview' => [
            'text_blocks' => [
                ['type' => 'paragraph', 'snippet' => 'Popular CRMs for agencies include:'],
                ['type' => 'list', 'list' => [
                    ['title' => 'Acme', 'snippet' => 'Best for small agencies.'],
                    ['title' => 'Globex', 'snippet' => 'Good reporting.'],
                ]],
                ['type' => 'heading', 'snippet' => 'Pricing'],
            ],
            'references' => [
                ['link' => 'https://acme.com/crm', 'title' => 'Acme CRM'],
                ['link' => 'https://globex.io', 'source' => 'Globex'],
            ],
        ],
    ])]);

    $answer = askGoogle('google_ai_overview');

    expect($answer->answer)->toContain('Popular CRMs')
        ->and($answer->answer)->toContain('- Acme: Best for small agencies.')
        ->and($answer->answer)->toContain('## Pricing')
        ->and($answer->citations)->toBe([
            ['url' => 'https://acme.com/crm', 'title' => 'Acme CRM'],
            ['url' => 'https://globex.io', 'title' => 'Globex'],
        ])
        ->and($answer->searches)->toBe(1)
        ->and($answer->model)->toBe('google_ai_overview');

    Http::assertSent(fn (Request $request) => $request['engine'] === 'google' && $request['gl'] === 'gb' && $request['api_key'] === 'serp-key');
});

it('fetches AI Overviews that load separately with a second request', function () {
    Http::fake(['serpapi.com/search.json*' => Http::sequence()
        ->push(['ai_overview' => ['page_token' => 'tok-1']])
        ->push(['ai_overview' => ['text_blocks' => [['type' => 'paragraph', 'snippet' => 'Acme is the top pick.']]]]),
    ]);

    $answer = askGoogle('google_ai_overview');

    expect($answer->answer)->toBe('Acme is the top pick.')
        ->and($answer->searches)->toBe(2);

    Http::assertSent(fn (Request $request) => $request['engine'] === 'google_ai_overview' && $request['page_token'] === 'tok-1');
});

it('reads Google AI Mode answers', function () {
    Http::fake(['serpapi.com/search.json*' => Http::response([
        'reconstructed_markdown' => "**Acme** is a strong choice.\n\n- Globex",
        'text_blocks' => [['type' => 'paragraph', 'snippet' => 'ignored']],
        'references' => [['link' => 'https://acme.com', 'title' => 'Acme']],
    ])]);

    $answer = askGoogle('google_ai_mode', null);

    expect($answer->answer)->toBe("**Acme** is a strong choice.\n\n- Globex")
        ->and($answer->citations)->toHaveCount(1);

    Http::assertSent(fn (Request $request) => $request['engine'] === 'google_ai_mode' && ! isset($request['gl']));
});

it('records searches without an AI answer instead of failing them', function () {
    Http::fake(['serpapi.com/search.json*' => Http::response(['error' => "Google hasn't returned any results for this query."])]);

    expect(askGoogle('google_ai_overview')->answer)->toBe(SerpApiGoogleEngine::NO_ANSWER);
});

it('pauses on SerpAPI key and quota problems', function (int $status, string $error, ?PauseReason $reason) {
    Http::fake(['serpapi.com/*' => Http::response(['error' => $error], $status)]);

    try {
        askGoogle('google_ai_mode');
        $this->fail('Expected a failure.');
    } catch (EngineRequestFailed $e) {
        expect($e->reason)->toBe($reason);
    }
})->with([
    'bad key' => [401, 'Invalid API key. Your API key should be here: https://serpapi.com/manage-api-key', PauseReason::InvalidKey],
    'out of searches' => [429, 'Your account has run out of searches.', PauseReason::InsufficientCredits],
    'rate limited' => [429, 'Too many requests', PauseReason::RateLimited],
    'outage' => [503, 'Service unavailable', PauseReason::ProviderOutage],
]);

it('shares one SerpAPI key between both Google engines', function () {
    app(KeyResolver::class)->store('google_ai_overview', ' serp-key ');

    expect(ProviderKey::query()->pluck('engine')->all())->toBe(['serpapi'])
        ->and(app(KeyResolver::class)->resolve('google_ai_mode'))->toBe('serp-key');

    app(KeyResolver::class)->remove('google_ai_mode');

    expect(app(KeyResolver::class)->has('google_ai_overview'))->toBeFalse();
});

it('reads the SerpAPI key from the environment', function () {
    config(['ai-visibility.keys.serpapi' => 'env-serp']);

    expect(app(KeyResolver::class)->source('google_ai_mode'))->toBe(['key' => 'env-serp', 'source' => 'env']);
});

it('never uses the Google engines for helper features', function () {
    app(KeyResolver::class)->store('google_ai_overview', 'serp-key');

    expect(app(EngineManager::class)->helper())->toBeNull();

    app(Settings::class)->set(['helpers' => ['engine' => 'google_ai_mode']]);

    expect(app(EngineManager::class)->helper())->toBeNull();

    app(Settings::class)->set(['helpers' => ['engine' => 'auto']]);
    app(KeyResolver::class)->store('openai', 'sk-test');

    expect(app(EngineManager::class)->helper()['engine']->key())->toBe('openai');
});

it('tests SerpAPI keys against the account endpoint', function () {
    Http::fake(['serpapi.com/account.json*' => Http::sequence()
        ->push(['total_searches_left' => 240])
        ->push(['total_searches_left' => 0])
        ->push(['error' => 'Invalid API key.'], 401),
    ]);

    $engine = app(EngineRegistry::class)->get('google_ai_mode');

    expect($engine->testKey('k')->ok)->toBeTrue()
        ->and($engine->testKey('k')->reason)->toBe(PauseReason::InsufficientCredits)
        ->and($engine->testKey('k')->reason)->toBe(PauseReason::InvalidKey);
});
