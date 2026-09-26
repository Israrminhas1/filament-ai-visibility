<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Models\EngineState;
use IsrarMinhas\FilamentAiVisibility\Models\ProviderKey;
use IsrarMinhas\FilamentAiVisibility\Runs\BudgetGuard;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

function slackAlerts(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($request) => str_contains($request->url(), 'hooks.slack.com'))
        ->map(fn ($request) => $request['text'])
        ->values()
        ->all();
}

describe('pause schedule', function () {
    it('keeps probing an engine whose test fails with an outage or rate limit', function () {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Service unavailable']], 503)
            ->push(['error' => ['message' => 'Rate limit reached']], 429)
            ->push(['error' => ['message' => 'Incorrect API key provided']], 401)]);
        app(KeyResolver::class)->store('openai', 'sk');
        $engines = app(EngineManager::class);

        $engines->testAndResume('openai');
        $state = $engines->state('openai');
        expect($state->reason)->toBe(PauseReason::ProviderOutage)
            ->and($state->next_probe_at?->isFuture())->toBeTrue();

        $this->travel(20)->minutes();
        expect($engines->dueForProbe())->toBe(['openai']);

        $engines->testAndResume('openai');
        $state = $engines->state('openai');
        expect($state->reason)->toBe(PauseReason::RateLimited)
            ->and($state->resume_after?->isFuture())->toBeTrue();

        // A key problem waits for a person.
        $engines->testAndResume('openai');
        $state = $engines->state('openai');
        expect($state->reason)->toBe(PauseReason::InvalidKey)
            ->and($state->next_probe_at)->toBeNull()
            ->and($state->resume_after)->toBeNull();
    });

    it('keeps a future probe time when the same pause is repeated', function () {
        $engines = app(EngineManager::class);

        $engines->pause('openai', PauseReason::InsufficientCredits);
        $first = $engines->state('openai')->next_probe_at;

        $this->travel(10)->minutes();
        $engines->pause('openai', PauseReason::InsufficientCredits);

        expect($engines->state('openai')->next_probe_at->equalTo($first))->toBeTrue();
    });

    it('doubles the outage back-off and resets it after a real answer', function () {
        config(['ai-visibility.reliability.failure_threshold' => 1]);
        $engines = app(EngineManager::class);

        $pauseMinutes = function () use ($engines) {
            $engines->recordFailure('openai', PauseReason::ProviderOutage, 'down');
            $minutes = (int) round(now()->diffInMinutes($engines->state('openai')->next_probe_at));
            $engines->resume('openai');

            return $minutes;
        };

        expect($pauseMinutes())->toBe(15)
            ->and($pauseMinutes())->toBe(30)
            ->and($pauseMinutes())->toBe(60);

        $engines->recordSuccess('openai');

        expect($pauseMinutes())->toBe(15);
    });
});

describe('recording a success', function () {
    it('only ends pauses that end on their own', function (PauseReason $reason, EngineStatus $expected) {
        $engines = app(EngineManager::class);
        $engines->pause('openai', $reason);

        $engines->recordSuccess('openai');

        expect($engines->state('openai')->status)->toBe($expected);
    })->with([
        'outage' => [PauseReason::ProviderOutage, EngineStatus::Active],
        'rate limit' => [PauseReason::RateLimited, EngineStatus::Active],
        'credits' => [PauseReason::InsufficientCredits, EngineStatus::Active],
        'manual' => [PauseReason::Manual, EngineStatus::Paused],
        'budget' => [PauseReason::Budget, EngineStatus::Paused],
        'invalid key' => [PauseReason::InvalidKey, EngineStatus::Paused],
        'missing key' => [PauseReason::MissingKey, EngineStatus::Paused],
    ]);
});

describe('budget and alerts', function () {
    beforeEach(function () {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        app(Settings::class)->set([
            'engines' => ['enabled' => ['openai', 'anthropic']],
            'alerts' => ['slack_webhook' => 'https://hooks.slack.com/services/T/B/X'],
        ]);
    });

    it('does not turn another pause into a budget pause, or lift it with the budget', function () {
        $brand = $this->createBrand();
        $spend = (object) ['over' => true];

        $this->mock(Spend::class, function ($mock) use ($spend) {
            $mock->shouldReceive('monthlyBudget')->andReturn(10.0);
            $mock->shouldReceive('thisMonth')->andReturnUsing(fn () => $spend->over ? 12.0 : 1.0);
            $mock->shouldReceive('brandBudget')->andReturn(null);
            $mock->shouldReceive('overBudget')->andReturnUsing(fn () => $spend->over);
        });

        $engines = app(EngineManager::class);
        $engines->pause('openai', PauseReason::InvalidKey);

        app(BudgetGuard::class)->enforce($brand);

        expect($engines->state('openai')->reason)->toBe(PauseReason::InvalidKey)
            ->and($engines->state('anthropic')->reason)->toBe(PauseReason::Budget);

        $spend->over = false;
        app(BudgetGuard::class)->release();

        expect($engines->state('openai')->reason)->toBe(PauseReason::InvalidKey)
            ->and($engines->state('anthropic')->status)->toBe(EngineStatus::Active);
    });

    it('sends one paused alert per reason every few hours', function () {
        $engines = app(EngineManager::class);

        $engines->pause('openai', PauseReason::ProviderOutage);
        $engines->resume('openai');
        $engines->pause('openai', PauseReason::ProviderOutage);
        $engines->resume('openai');

        expect(slackAlerts())->toHaveCount(2)
            ->and(slackAlerts()[0])->toContain('paused: Provider outage')
            ->and(slackAlerts()[1])->toContain('resumed');

        // Another reason is news.
        $engines->pause('openai', PauseReason::InvalidKey);
        expect(slackAlerts())->toHaveCount(3);

        $this->travel(7)->hours();
        $engines->resume('openai');
        $engines->pause('openai', PauseReason::ProviderOutage);

        expect(slackAlerts())->toHaveCount(5);
    });
});

describe('failure classification', function () {
    it('reads structured error fields before the wording', function (string $engine, string $url, int $status, array $body, PauseReason $reason) {
        Http::fake([$url => Http::response($body, $status)]);

        expect(app(EngineRegistry::class)->get($engine)->testKey('key')->reason)->toBe($reason);
    })->with([
        'gemini per-minute limit' => ['gemini', 'generativelanguage.googleapis.com/*', 429, ['error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => "You exceeded your current quota, please check your plan and billing details. For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits.\n* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 10, model: gemini-2.5-flash\nPlease retry in 36.8s.",
        ]], PauseReason::RateLimited],
        'gemini prepaid credits' => ['gemini', 'generativelanguage.googleapis.com/*', 429, ['error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'Your prepayment credits are depleted. Please go to AI Studio to manage your project and billing.',
        ]], PauseReason::InsufficientCredits],
        'gemini model needs billing' => ['gemini', 'generativelanguage.googleapis.com/*', 429, ['error' => [
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'You exceeded your current quota. Quota exceeded for metric: generate_content_free_tier_requests, limit: 0, model: gemini-2.5-pro',
        ]], PauseReason::InsufficientCredits],
        'openai quota code' => ['openai', 'api.openai.com/*', 429, ['error' => ['code' => 'insufficient_quota', 'message' => 'Quota']], PauseReason::InsufficientCredits],
        'openai rate limit code' => ['openai', 'api.openai.com/*', 429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Check your plan and billing details']], PauseReason::RateLimited],
        'anthropic billing type' => ['anthropic', 'api.anthropic.com/*', 400, ['type' => 'error', 'error' => ['type' => 'billing_error', 'message' => 'Low balance']], PauseReason::InsufficientCredits],
    ]);

    it('takes the wait from Google\'s retry details', function () {
        $failed = EngineRequestFailed::fromStatus(429, (string) json_encode(['error' => [
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'Quota exceeded',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '36s']],
        ]]), 'Gemini: quota');

        expect($failed->reason)->toBe(PauseReason::RateLimited)
            ->and($failed->retryAfter)->toBe(36);
    });

    it('does not blame the model when a batch has expired', function () {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'No such File object: file-abc']], 404)]);

        try {
            app(EngineRegistry::class)->get('openai')->batchStatus('sk', 'batch_1');
            $this->fail('Expected the poll to fail.');
        } catch (EngineRequestFailed $e) {
            expect($e->reason)->toBeNull();
        }
    });
});

describe('answers', function () {
    it('keeps a Claude preamble apart from the answer, but joins cited pieces', function () {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-5',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            'content' => [
                ['type' => 'text', 'text' => "I'll look into this for you."],
                ['type' => 'server_tool_use', 'id' => 'st_1', 'name' => 'web_search', 'input' => ['query' => 'gifts']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'st_1', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://example.com/gifts', 'title' => 'Gifts'],
                ]],
                ['type' => 'text', 'text' => 'Here are my '],
                ['type' => 'text', 'text' => 'recommendations.', 'citations' => [
                    ['type' => 'web_search_result_location', 'url' => 'https://example.com/gifts', 'title' => 'Gifts', 'cited_text' => '…'],
                ]],
                ['type' => 'text', 'text' => ' For an 8-year-old, try Lego.'],
            ],
        ])]);

        $answer = app(EngineRegistry::class)->get('anthropic')->ask(new EngineRequest('Gift ideas?', 'claude-sonnet-4-5', 'sk-ant'));

        expect($answer->answer)->toBe("I'll look into this for you.\n\nHere are my recommendations. For an 8-year-old, try Lego.");
    });

    it('counts Gemini thinking tokens as output', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Acme is good.']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 400],
            'modelVersion' => 'gemini-2.5-flash',
        ])]);

        $answer = app(EngineRegistry::class)->get('gemini')->ask(new EngineRequest('Best CRM?', 'gemini-2.5-flash', 'g-key'));

        expect($answer->outputTokens)->toBe(450)
            ->and($answer->inputTokens)->toBe(10);
    });
});

describe('keys', function () {
    it('treats a stored key that can no longer be decrypted as missing', function () {
        app(KeyResolver::class)->store('openai', 'sk-panel');
        ProviderKey::query()->toBase()->update(['api_key' => 'encrypted-with-an-old-app-key']);

        expect(app(KeyResolver::class)->resolve('openai'))->toBeNull()
            ->and(app(KeyResolver::class)->has('openai'))->toBeFalse();

        config(['ai-visibility.keys.openai' => 'sk-env']);

        expect(app(KeyResolver::class)->source('openai'))->toBe(['key' => 'sk-env', 'source' => 'env']);
    });

    it('never stores the SerpAPI key in an error', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://serpapi.com/search.json?engine=google_ai_mode&q=crm&api_key=serp-secret-123'));

        try {
            app(EngineRegistry::class)->get('google_ai_mode')->ask(new EngineRequest('Best CRM?', 'google_ai_mode', 'serp-secret-123'));
            $this->fail('Expected the request to fail.');
        } catch (EngineRequestFailed $e) {
            expect($e->getMessage())->not->toContain('serp-secret-123')
                ->and($e->getMessage())->toContain('api_key=[redacted]');
        }
    });
});

describe('state rows and tenants', function () {
    it('keeps one state row per engine', function () {
        $engines = app(EngineManager::class);

        $engines->state('openai');
        $engines->state('openai');
        $engines->pause('openai', PauseReason::Manual);

        expect(EngineState::query()->where('engine', 'openai')->count())->toBe(1);
    });

    it('checks the engines of every tenant from the command line', function () {
        foreach (['team-a', 'team-b'] as $tenant) {
            Tenancy::as($tenant, fn () => app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]));
        }

        $this->artisan('ai-visibility:health')
            ->expectsOutputToContain('[tenant team-a] OpenAI (ChatGPT): paused')
            ->expectsOutputToContain('[tenant team-b] OpenAI (ChatGPT): paused')
            ->assertFailed();

        expect(EngineState::query()->withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(0)
            ->and(EngineState::query()->withoutGlobalScopes()->pluck('tenant_id')->sort()->values()->all())->toBe(['team-a', 'team-b']);
    });
});
