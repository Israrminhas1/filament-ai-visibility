<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\Pages\ViewRun;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Jobs\SubmitBatchJob;
use IsrarMinhas\FilamentAiVisibility\Models\Batch;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Support\Pricing;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

/**
 * Start a scheduled run and submit its batches, like a worker would.
 */
function scheduledRun($brand): Run
{
    $run = app(RunPlanner::class)->start($brand, RunTrigger::Schedule);

    foreach (Queue::pushed(SubmitBatchJob::class) as $job) {
        $job->handle();
    }

    return $run;
}

function responsesBody(string $text): array
{
    return [
        'model' => 'gpt-5-mini',
        'output' => [
            ['type' => 'web_search_call'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
        ],
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
    ];
}

function jsonl(array $lines): string
{
    return implode("\n", array_map('json_encode', $lines));
}

beforeEach(function () {
    Queue::fake();
    $this->completeSetup();
    app(Settings::class)->set(['engines' => ['enabled' => ['openai'], 'economy' => true]]);
    app(KeyResolver::class)->store('openai', 'sk-test');

    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->brand->prompts()->create(['text' => 'Best CRM for agencies?']);
    $this->brand->prompts()->create(['text' => 'Cheapest CRM?']);
});

it('batches scheduled runs but answers manual runs in real time', function () {
    app(RunPlanner::class)->start($this->brand, RunTrigger::Schedule);

    Queue::assertPushed(SubmitBatchJob::class, fn ($job) => $job->engine === 'openai' && count($job->resultIds) === 2);
    Queue::assertNotPushed(RunResultJob::class);

    app(RunPlanner::class)->start($this->brand, RunTrigger::Manual);

    Queue::assertPushed(RunResultJob::class, 2);
});

it('answers in real time when economy mode is off or the engine has no batch API', function () {
    app(Settings::class)->set(['engines' => ['economy' => false]]);
    app(RunPlanner::class)->start($this->brand, RunTrigger::Schedule);

    Queue::assertPushed(RunResultJob::class, 2);

    Queue::fake();
    app(Settings::class)->set(['engines' => ['enabled' => ['perplexity'], 'economy' => true]]);
    app(KeyResolver::class)->store('perplexity', 'pplx');
    $this->brand->update(['last_run_at' => null]);
    app(RunPlanner::class)->start($this->brand, RunTrigger::Schedule);

    Queue::assertPushed(RunResultJob::class, 2);
    Queue::assertNotPushed(SubmitBatchJob::class);
});

it('runs a full OpenAI batch at the discounted price and retries rejected items in real time', function () {
    Http::fake([
        'api.openai.com/v1/files' => Http::response(['id' => 'file-in']),
        'api.openai.com/v1/batches' => Http::response(['id' => 'batch_1', 'status' => 'validating']),
        'api.openai.com/v1/batches/batch_1' => Http::sequence()
            ->push(['id' => 'batch_1', 'status' => 'in_progress'])
            ->push(['id' => 'batch_1', 'status' => 'completed', 'output_file_id' => 'file-out', 'error_file_id' => 'file-err'])
            ->push(['id' => 'batch_1', 'status' => 'completed', 'output_file_id' => 'file-out', 'error_file_id' => 'file-err']),
        'api.openai.com/v1/files/file-out/content' => fn () => Http::response(jsonl([
            ['custom_id' => 'result-' . Result::query()->min('id'), 'response' => ['status_code' => 200, 'body' => responsesBody('Acme is the best CRM.')]],
        ])),
        'api.openai.com/v1/files/file-err/content' => fn () => Http::response(jsonl([
            ['custom_id' => 'result-' . Result::query()->max('id'), 'response' => ['status_code' => 400, 'body' => ['error' => ['message' => 'Tool not supported in batch']]]],
        ])),
    ]);

    $run = scheduledRun($this->brand);
    [$first, $second] = $run->results()->orderBy('id')->get()->all();

    $batch = Batch::query()->sole();

    expect($batch->provider_batch_id)->toBe('batch_1')
        ->and($batch->result_ids)->toBe([$first->id, $second->id]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/files'
        && $request->isMultipart()
        && $request->hasHeader('Authorization', 'Bearer sk-test')
        && collect($request->data())->firstWhere('name', 'purpose')['contents'] === 'batch'
        && json_decode(explode("\n", collect($request->data())->firstWhere('name', 'file')['contents'])[0], true)['body']['tools'][0]['type'] === 'web_search');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/batches'
        && $request['input_file_id'] === 'file-in'
        && $request['endpoint'] === '/v1/responses'
        && $request['completion_window'] === '24h');

    // Still running at the provider.
    $this->artisan('ai-visibility:poll-batches')->assertSuccessful();
    expect($batch->fresh()->status)->toBe(Batch::SUBMITTED);

    $this->artisan('ai-visibility:poll-batches')->assertSuccessful();

    $first->refresh();
    $fullPrice = app(Pricing::class)->cost('openai', 'gpt-5-mini', 1000, 500, 1);
    $searchFee = (float) config('ai-visibility.pricing.search_fee.openai');

    expect($batch->fresh()->status)->toBe(Batch::COMPLETED)
        ->and($first->status)->toBe(ResultStatus::Success)
        ->and($first->brand_mentioned)->toBeTrue()
        ->and((float) $first->cost_usd)->toEqualWithDelta(($fullPrice - $searchFee) / 2 + $searchFee, 0.000001)
        ->and($second->fresh()->status)->toBe(ResultStatus::Pending);

    Queue::assertPushed(RunResultJob::class, fn ($job) => $job->resultId === $second->id);
});

it('runs Claude batches and pauses the engine on account errors', function () {
    app(Settings::class)->set(['engines' => ['enabled' => ['anthropic']]]);
    app(KeyResolver::class)->store('anthropic', 'sk-ant');
    $this->brand->prompts()->create(['text' => 'CRM with email?']);

    Http::fake([
        'api.anthropic.com/v1/messages/batches' => Http::response(['id' => 'msgbatch_1', 'processing_status' => 'in_progress']),
        'api.anthropic.com/v1/messages/batches/msgbatch_1' => Http::response(['id' => 'msgbatch_1', 'processing_status' => 'ended']),
        'api.anthropic.com/v1/messages/batches/msgbatch_1/results' => function () {
            [$ok, $expired, $billing] = Result::query()->orderBy('id')->pluck('id')->all();

            return Http::response(jsonl([
                ['custom_id' => "result-{$ok}", 'result' => ['type' => 'succeeded', 'message' => [
                    'model' => 'claude-sonnet-5',
                    'content' => [['type' => 'text', 'text' => 'Try Acme.', 'citations' => [['url' => 'https://acme.com', 'title' => 'Acme']]]],
                    'usage' => ['input_tokens' => 800, 'output_tokens' => 200, 'server_tool_use' => ['web_search_requests' => 1]],
                ]]],
                ['custom_id' => "result-{$expired}", 'result' => ['type' => 'expired']],
                ['custom_id' => "result-{$billing}", 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'billing_error', 'message' => 'Your credit balance is too low.']]]],
            ]));
        },
    ]);

    $run = scheduledRun($this->brand);
    [$ok, $expired, $billing] = $run->results()->orderBy('id')->get()->all();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages/batches'
        && count($request['requests']) === 3
        && $request['requests'][0]['custom_id'] === "result-{$ok->id}"
        && $request['requests'][0]['params']['tools'][0]['name'] === 'web_search');

    $this->artisan('ai-visibility:poll-batches')->assertSuccessful();

    expect($ok->fresh()->status)->toBe(ResultStatus::Success)
        ->and($ok->fresh()->citations()->count())->toBe(1)
        ->and($billing->fresh()->status)->toBe(ResultStatus::Skipped)
        ->and($billing->fresh()->skip_reason)->toBe('engine_paused:' . PauseReason::InsufficientCredits->value)
        ->and(app(EngineManager::class)->state('anthropic')->status)->toBe(EngineStatus::Paused)
        ->and($expired->fresh()->status)->toBe(ResultStatus::Pending);

    Queue::assertPushed(RunResultJob::class, fn ($job) => $job->resultId === $expired->id);
});

it('pauses and skips when a batch cannot be submitted because of the key', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401)]);

    $run = scheduledRun($this->brand);

    expect(Batch::query()->count())->toBe(0)
        ->and(app(EngineManager::class)->state('openai')->reason)->toBe(PauseReason::InvalidKey)
        ->and($run->fresh()->results_skipped)->toBe(2)
        ->and($run->fresh()->status)->not->toBe(RunStatus::Running);

    Queue::assertNotPushed(RunResultJob::class);
});

it('falls back to real time when the batch API is down', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Server error']], 500)]);

    scheduledRun($this->brand);

    expect(Batch::query()->count())->toBe(0);
    Queue::assertPushed(RunResultJob::class, 2);
});

it('retries in real time when a batch fails or takes too long', function () {
    Http::fake([
        'api.openai.com/v1/files' => Http::response(['id' => 'file-in']),
        'api.openai.com/v1/batches' => Http::response(['id' => 'batch_1']),
        'api.openai.com/v1/batches/batch_1' => Http::response(['status' => 'failed', 'errors' => ['data' => [['message' => 'Invalid model']]]]),
        'api.openai.com/v1/batches/batch_2' => Http::response(['status' => 'in_progress']),
    ]);

    scheduledRun($this->brand);
    $this->artisan('ai-visibility:poll-batches')->assertSuccessful();

    expect(Batch::query()->sole()->status)->toBe(Batch::FAILED)
        ->and(Batch::query()->sole()->error)->toBe('Invalid model');
    Queue::assertPushed(RunResultJob::class, 2);

    // A batch still running after the time limit.
    Queue::fake();
    $batch = Batch::query()->sole()->replicate()->fill(['provider_batch_id' => 'batch_2', 'status' => Batch::SUBMITTED, 'error' => null]);
    $batch->save();

    $this->artisan('ai-visibility:poll-batches')->assertSuccessful();
    expect($batch->fresh()->status)->toBe(Batch::SUBMITTED);
    Queue::assertNotPushed(RunResultJob::class);

    $this->travel(27)->hours();
    $this->artisan('ai-visibility:poll-batches')->assertSuccessful();

    expect($batch->fresh()->status)->toBe(Batch::FAILED);
    Queue::assertPushed(RunResultJob::class, 2);
});

it('explains on the run page that answers are waiting for a batch', function () {
    $this->actingAs($this->createUser());
    Http::fake([
        'api.openai.com/v1/files' => Http::response(['id' => 'file-in']),
        'api.openai.com/v1/batches' => Http::response(['id' => 'batch_1']),
    ]);

    $run = scheduledRun($this->brand);

    livewire(ViewRun::class, ['record' => $run->getRouteKey()])
        ->assertSee('Waiting for 2 answers from OpenAI (ChatGPT) batch APIs');
});

it('is turned on from the Engines settings', function () {
    $this->actingAs($this->createUser());
    app(Settings::class)->set(['engines' => ['economy' => false]]);

    livewire(ManageSettings::class)
        ->assertFormSet(['engines_economy' => false])
        ->fillForm(['engines_economy' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->get('engines.economy'))->toBeTrue()
        ->and(app(Settings::class)->get('engines.enabled'))->toBe(['openai']);
});

it('skips batched answers when everything is paused before submission', function () {
    Http::fake();
    app(RunPlanner::class)->start($this->brand, RunTrigger::Schedule);
    app(Settings::class)->setKillSwitch(true);

    foreach (Queue::pushed(SubmitBatchJob::class) as $job) {
        $job->handle();
    }

    expect(Result::query()->where('status', ResultStatus::Skipped)->count())->toBe(2);
    Http::assertNothingSent();
});
