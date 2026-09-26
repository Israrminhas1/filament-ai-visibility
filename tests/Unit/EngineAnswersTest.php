<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;

function ask(string $engine, string $model = 'test-model', ?string $country = null)
{
    return app(EngineRegistry::class)->get($engine)->ask(new EngineRequest('Best CRM for agencies?', $model, 'key', $country));
}

it('reads OpenAI Responses API answers with web search', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response([
        'model' => 'gpt-5-mini-2025-08-07',
        'output' => [
            ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
            ['type' => 'message', 'role' => 'assistant', 'content' => [[
                'type' => 'output_text',
                'text' => 'Acme is great for agencies.',
                'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://acme.com/agencies', 'title' => 'Acme for agencies'],
                    ['type' => 'url_citation', 'url' => 'https://acme.com/agencies', 'title' => 'Duplicate'],
                ],
            ]]],
        ],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
    ])]);

    $answer = ask('openai', 'gpt-5-mini', 'GB');

    expect($answer->answer)->toBe('Acme is great for agencies.')
        ->and($answer->citations)->toBe([['url' => 'https://acme.com/agencies', 'title' => 'Acme for agencies']])
        ->and($answer->model)->toBe('gpt-5-mini-2025-08-07')
        ->and([$answer->inputTokens, $answer->outputTokens, $answer->searches])->toBe([120, 40, 1]);

    Http::assertSent(fn ($request) => $request['tools'] === [['type' => 'web_search', 'user_location' => ['type' => 'approximate', 'country' => 'GB']]]);
});

it('reads Claude answers with web search and continues paused turns', function () {
    Http::fake(['api.anthropic.com/v1/messages' => Http::sequence()
        ->push([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'pause_turn',
            'content' => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'crm agencies']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                    ['type' => 'web_search_result', 'url' => 'https://g2.com/crm', 'title' => 'Best CRM'],
                ]],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10, 'server_tool_use' => ['web_search_requests' => 1]],
        ])
        ->push([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'text', 'text' => 'Try Acme. ', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://acme.com', 'title' => 'Acme']]],
                ['type' => 'text', 'text' => 'Or Globex.'],
            ],
            'usage' => ['input_tokens' => 150, 'output_tokens' => 30],
        ])]);

    $answer = ask('anthropic', 'claude-sonnet-5');

    expect($answer->answer)->toBe('Try Acme. Or Globex.')
        ->and(array_column($answer->citations, 'url'))->toBe(['https://acme.com', 'https://g2.com/crm'])
        ->and([$answer->inputTokens, $answer->outputTokens, $answer->searches])->toBe([250, 40, 1]);

    Http::assertSentCount(2);
});

it('uses the basic web search tool on every Claude model, so sources are always returned', function (string $model) {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hi']], 'stop_reason' => 'end_turn', 'usage' => []])]);

    ask('anthropic', $model, 'GB');

    Http::assertSent(fn ($request) => $request['tools'][0]['type'] === 'web_search_20250305'
        && $request['tools'][0]['user_location']['country'] === 'GB');
})->with(['claude-fable-5-1', 'claude-opus-5-5', 'claude-sonnet-5', 'claude-haiku-4-5']);

it('reads Gemini grounded answers, turning redirect links into real domains', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'modelVersion' => 'gemini-2.5-flash',
        'candidates' => [[
            'content' => ['parts' => [['text' => 'Acme and '], ['text' => 'Globex are popular.']]],
            'groundingMetadata' => [
                'webSearchQueries' => ['best crm agencies'],
                'groundingChunks' => [
                    ['web' => ['uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/abc', 'title' => 'acme.com']],
                    ['web' => ['uri' => 'https://globex.io/crm', 'title' => 'Globex CRM']],
                ],
            ],
        ]],
        'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 20],
    ])]);

    $answer = ask('gemini', 'gemini-2.5-flash');

    expect($answer->answer)->toBe('Acme and Globex are popular.')
        ->and(array_column($answer->citations, 'url'))->toBe(['https://acme.com/', 'https://globex.io/crm'])
        ->and($answer->searches)->toBe(1);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/models/gemini-2.5-flash:generateContent') && isset($request['tools'][0]['google_search']));
});

it('reads Perplexity Agent API and Grok answers', function () {
    Http::fake([
        'api.perplexity.ai/*' => Http::response([
            'model' => 'perplexity/sonar',
            'output' => [
                ['type' => 'search_results', 'queries' => ['crm'], 'results' => [
                    ['id' => 1, 'title' => 'Acme', 'url' => 'https://acme.com'],
                    ['id' => 2, 'title' => 'G2', 'url' => 'https://g2.com/crm'],
                ]],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Acme [1].', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://acme.com', 'title' => 'Acme'],
                ]]]],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'tool_calls_details' => ['web_search' => ['invocation' => 2]]],
        ]),
        'api.x.ai/*' => Http::response([
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Globex.']]]],
            'citations' => ['https://globex.io', 'not a url'],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'num_sources_used' => 3],
        ]),
    ]);

    $perplexity = ask('perplexity', 'sonar');
    $grok = ask('grok', 'grok-4.7');

    expect($perplexity->answer)->toBe('Acme [1].')
        ->and($perplexity->citations)->toBe([['url' => 'https://acme.com', 'title' => 'Acme'], ['url' => 'https://g2.com/crm', 'title' => 'G2']])
        ->and([$perplexity->model, $perplexity->inputTokens, $perplexity->outputTokens, $perplexity->searches])->toBe(['perplexity/sonar', 10, 5, 2])
        ->and($grok->answer)->toBe('Globex.')
        ->and($grok->citations)->toBe([['url' => 'https://globex.io', 'title' => null]])
        ->and($grok->searches)->toBe(1);
});

it('turns failures into engine-level reasons', function (int $status, array $body, ?PauseReason $reason, bool $transient) {
    Http::fake(['api.openai.com/*' => Http::response($body, $status, ['retry-after' => '42'])]);

    try {
        ask('openai');
        $this->fail('Expected EngineRequestFailed');
    } catch (EngineRequestFailed $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->isTransient())->toBe($transient)
            ->and($e->retryAfter)->toBe(42);
    }
})->with([
    'bad key' => [401, ['error' => ['message' => 'Invalid key']], PauseReason::InvalidKey, false],
    'no credits' => [429, ['error' => ['code' => 'insufficient_quota']], PauseReason::InsufficientCredits, false],
    'rate limit' => [429, ['error' => ['message' => 'slow down']], PauseReason::RateLimited, true],
    'outage' => [502, ['error' => ['message' => 'bad gateway']], PauseReason::ProviderOutage, true],
    'bad request' => [400, ['error' => ['message' => 'content policy']], null, false],
]);

it('treats connection errors as an outage', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

    expect(fn () => ask('openai'))->toThrow(fn (EngineRequestFailed $e) => expect($e->reason)->toBe(PauseReason::ProviderOutage));
});

it('sends Perplexity requests to the Agent API with web search, and maps old Sonar names', function () {
    Http::fake(['api.perplexity.ai/*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi']]]]])]);

    ask('perplexity', 'sonar', 'GB');
    ask('perplexity', 'sonar-pro');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.perplexity.ai/v1/agent'
        && ($request['model'] ?? null) === 'perplexity/sonar'
        && $request['tools'][0] === ['type' => 'web_search', 'user_location' => ['country' => 'GB']]
        && $request['max_output_tokens'] > 0);

    Http::assertSent(fn ($request) => ($request['preset'] ?? null) === 'low' && ! isset($request['model']));
});

it('counts every Gemini 3 search query, but one per prompt on Gemini 2.5', function (string $model, int $searches) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'modelVersion' => $model,
        'candidates' => [['content' => ['parts' => [['text' => 'Acme.']]], 'groundingMetadata' => ['webSearchQueries' => ['crm', 'best crm', 'crm uk']]]],
    ])]);

    expect(ask('gemini', $model)->searches)->toBe($searches);
})->with([
    'gemini 3' => ['gemini-3.8-flash', 3],
    'gemini 2.5' => ['gemini-2.5-flash', 1],
]);

it('pauses the engine when the model cannot search or does not exist', function (string $engine, int $status, array $body) {
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        ask($engine, 'some-model');
        $this->fail('Expected a failure.');
    } catch (EngineRequestFailed $e) {
        expect($e->reason)->toBe(PauseReason::ModelUnavailable);
    }
})->with([
    'openai tool' => ['openai', 400, ['error' => ['message' => "Tool 'web_search' is not supported with gpt-4.1-nano."]]],
    'gemini grounding' => ['gemini', 400, ['error' => ['message' => 'Search Grounding is not supported.']]],
    'perplexity model' => ['perplexity', 400, ['error' => ['message' => 'Invalid model: perplexity/nope']]],
    'anthropic model' => ['anthropic', 404, ['error' => ['type' => 'not_found_error', 'message' => 'model: nope']]],
]);

it('does not pause for ordinary rejected requests', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Your input was flagged by the moderation system.']], 400)]);

    try {
        ask('openai', 'gpt-6-luna');
        $this->fail('Expected a failure.');
    } catch (EngineRequestFailed $e) {
        expect($e->reason)->toBeNull();
    }
});

it('only suggests current models that search the web', function () {
    $models = collect(app(\IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry::class)->all())
        ->mapWithKeys(fn ($engine) => [$engine->key() => $engine->suggestedModels()]);

    expect($models['openai'])->not->toContain('gpt-4o', 'gpt-4o-mini', 'gpt-5-nano', 'gpt-4.1-mini')
        ->and($models['perplexity'])->toBe(['perplexity/sonar']);

    // Every suggested model has a price, and the defaults are among the suggestions.
    foreach (app(\IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry::class)->all() as $engine) {
        expect($engine->suggestedModels())->toContain($engine->defaultTrackingModel());

        if (! str_starts_with($engine->key(), 'google_')) {
            foreach ($engine->suggestedModels() as $model) {
                expect(config('ai-visibility.pricing.models'))->toHaveKey($model);
            }
        }
    }
});
