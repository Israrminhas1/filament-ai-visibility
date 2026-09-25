<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\EngineResumed;
use IsrarMinhas\FilamentAiVisibility\Models\ProviderKey;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

describe('key tests', function () {
    it('reports success and available models', function () {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-5'], ['id' => 'gpt-5-mini']]])]);

        $result = app(EngineRegistry::class)->get('openai')->testKey('sk-test');

        expect($result->ok)->toBeTrue()
            ->and($result->models)->toBe(['gpt-5', 'gpt-5-mini']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test'));
    });

    it('classifies provider failures', function (string $engine, string $url, int $status, array $body, PauseReason $reason) {
        Http::fake([$url => Http::response($body, $status)]);

        $result = app(EngineRegistry::class)->get($engine)->testKey('bad');

        expect($result->ok)->toBeFalse()
            ->and($result->reason)->toBe($reason);
    })->with([
        'invalid key' => ['openai', 'api.openai.com/*', 401, ['error' => ['message' => 'Incorrect API key provided']], PauseReason::InvalidKey],
        'no credits (quota)' => ['openai', 'api.openai.com/*', 429, ['error' => ['code' => 'insufficient_quota', 'message' => 'You exceeded your current quota']], PauseReason::InsufficientCredits],
        'rate limited' => ['openai', 'api.openai.com/*', 429, ['error' => ['message' => 'Rate limit reached']], PauseReason::RateLimited],
        'anthropic credits' => ['anthropic', 'api.anthropic.com/*', 400, ['error' => ['message' => 'Your credit balance is too low']], PauseReason::InsufficientCredits],
        'gemini invalid key' => ['gemini', 'generativelanguage.googleapis.com/*', 400, ['error' => ['message' => 'API key not valid. Please pass a valid API key.']], PauseReason::InvalidKey],
        'perplexity payment' => ['perplexity', 'api.perplexity.ai/*', 402, ['error' => ['message' => 'Payment required']], PauseReason::InsufficientCredits],
        'outage' => ['grok', 'api.x.ai/*', 503, ['error' => 'Service unavailable'], PauseReason::ProviderOutage],
    ]);

    it('sends the Gemini key as a header, never in the URL', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [['name' => 'models/gemini-2.5-flash'], ['name' => 'models/embedding-001']]])]);

        $result = app(EngineRegistry::class)->get('gemini')->testKey('g-key');

        expect($result->models)->toBe(['gemini-2.5-flash']);
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'g-key') && ! str_contains($request->url(), 'g-key'));
    });
});

describe('keys', function () {
    it('prefers a panel key, then AI Monitor, then the environment', function () {
        require_once __DIR__ . '/../Fixtures/FakeAiMonitor.php';
        \Filament\AiMonitor\Services\AiKeyManager::$keys = [];

        $keys = app(KeyResolver::class);
        config(['ai-visibility.keys.grok' => 'env-key']);

        expect($keys->source('grok'))->toBe(['key' => 'env-key', 'source' => 'env']);

        \Filament\AiMonitor\Services\AiKeyManager::$keys = ['xai' => 'monitor-key'];
        expect($keys->source('grok'))->toBe(['key' => 'monitor-key', 'source' => 'ai-monitor']);

        $keys->store('grok', ' panel-key ');
        expect($keys->source('grok'))->toBe(['key' => 'panel-key', 'source' => 'panel']);

        \Filament\AiMonitor\Services\AiKeyManager::$keys = [];
    });

    it('stores keys encrypted', function () {
        app(KeyResolver::class)->store('openai', 'sk-secret-value');

        expect(ProviderKey::query()->toBase()->value('api_key'))->not->toContain('sk-secret');
    });
});

describe('engine state', function () {
    beforeEach(function () {
        app(Settings::class)->set(['engines' => ['enabled' => ['openai', 'anthropic']]]);
    });

    it('pauses an enabled engine with no key, and resumes it when a key is added', function () {
        Event::fake([EnginePaused::class, EngineResumed::class]);
        app(KeyResolver::class)->store('anthropic', 'sk-ant');

        $engines = app(EngineManager::class);

        expect($engines->usable())->toBe(['anthropic'])
            ->and($engines->state('openai')->reason)->toBe(PauseReason::MissingKey);

        Event::assertDispatched(EnginePaused::class, fn ($e) => $e->engine === 'openai' && $e->reason === PauseReason::MissingKey);

        app(KeyResolver::class)->store('openai', 'sk-openai');

        expect($engines->usable())->toBe(['openai', 'anthropic']);
        Event::assertDispatched(EngineResumed::class, fn ($e) => $e->engine === 'openai');
    });

    it('pauses once per episode', function () {
        Event::fake([EnginePaused::class]);
        $engines = app(EngineManager::class);

        $engines->pause('openai', PauseReason::InsufficientCredits);
        $engines->pause('openai', PauseReason::InsufficientCredits);
        $engines->pause('openai', PauseReason::InvalidKey);

        Event::assertDispatchedTimes(EnginePaused::class, 2);
        expect($engines->state('openai')->status)->toBe(EngineStatus::Paused);
    });

    it('tests and resumes an engine', function () {
        Http::fake(['api.openai.com/*' => Http::sequence()->push(['error' => ['message' => 'bad']], 401)->push(['data' => []])]);
        app(KeyResolver::class)->store('openai', 'sk');
        $engines = app(EngineManager::class);

        expect($engines->testAndResume('openai')->ok)->toBeFalse()
            ->and($engines->state('openai')->reason)->toBe(PauseReason::InvalidKey)
            ->and($engines->testAndResume('openai')->ok)->toBeTrue()
            ->and($engines->state('openai')->status)->toBe(EngineStatus::Active);
    });

    it('picks the helper engine automatically, working with a single key', function () {
        $engines = app(EngineManager::class);

        expect($engines->helper())->toBeNull();

        app(KeyResolver::class)->store('gemini', 'g');
        expect($engines->helper()['engine']->key())->toBe('gemini')
            ->and($engines->helper()['model'])->toBe(config('ai-visibility.engines.gemini.helper_model'));

        app(KeyResolver::class)->store('openai', 'o');
        expect($engines->helper()['engine']->key())->toBe('openai');

        $engines->pause('openai', PauseReason::InsufficientCredits);
        expect($engines->helper()['engine']->key())->toBe('gemini');
    });

    it('returns no helper when an explicitly chosen engine is paused', function () {
        app(Settings::class)->set(['helpers' => ['engine' => 'openai', 'model' => 'gpt-5-nano']]);
        app(KeyResolver::class)->store('openai', 'o');
        app(KeyResolver::class)->store('gemini', 'g');
        $engines = app(EngineManager::class);

        expect($engines->helper()['model'])->toBe('gpt-5-nano');

        $engines->pause('openai', PauseReason::InvalidKey);
        expect($engines->helper())->toBeNull();
    });
});
