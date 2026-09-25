<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\EditBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\Pages\ViewRun;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = $this->createUser();
    $this->actingAs($this->user);
    $this->completeSetup();

    app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
    app(KeyResolver::class)->store('openai', 'sk');

    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->brand->competitors()->create(['name' => 'Globex']);
    $this->brand->prompts()->create(['text' => 'Best CRM for agencies?']);
});

function runAll(): void
{
    foreach (Queue::pushedJobs()[RunResultJob::class] ?? [] as $pushed) {
        $pushed['job']->withFakeQueueInteractions()->handle();
    }
}

it('starts a run from the brand page and notifies when it finishes', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Acme is great <script>alert(1)</script>.', 'annotations' => []]]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
    ])]);

    livewire(EditBrand::class, ['record' => $this->brand->getRouteKey()])
        ->assertActionExists('runNow')
        ->callAction('runNow')
        ->assertNotified('Run started');

    Queue::assertPushed(RunResultJob::class, 1);
    runAll();

    $run = Run::query()->first();

    expect($run->triggered_by)->toBe($this->user->id)
        ->and($this->user->notifications()->count())->toBe(1)
        ->and($this->user->notifications()->first()->data['title'])->toContain('Completed');

    $this->get(RunResource::getUrl())->assertOk()->assertSee('Acme');
    $this->get(RunResource::getUrl('view', ['record' => $run]))->assertOk()->assertSee('Answers collected');

    // The answer is shown with the brand highlighted and any HTML escaped.
    $this->get(ResultResource::getUrl('view', ['record' => Result::query()->first()]))
        ->assertOk()
        ->assertSee('<mark', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('explains why a run cannot start', function () {
    app(EngineManager::class)->pause('openai', PauseReason::InsufficientCredits);

    livewire(EditBrand::class, ['record' => $this->brand->getRouteKey()])
        ->callAction('runNow')
        ->assertNotified('Run not started');

    Queue::assertNothingPushed();
});

it('retries skipped answers from the run page', function () {
    $run = app(RunPlanner::class)->start($this->brand);
    app(EngineManager::class)->pause('openai', PauseReason::InvalidKey);
    runAll();

    expect($run->refresh()->results_skipped)->toBe(1);

    app(EngineManager::class)->resume('openai');
    Queue::fake();

    livewire(ViewRun::class, ['record' => $run->getRouteKey()])
        ->callAction('retrySkipped')
        ->assertNotified('Retrying 1 answers');

    Queue::assertPushed(RunResultJob::class, 1);
    expect(Result::query()->first()->status)->toBe(ResultStatus::Pending);
});

it('lists answers', function () {
    $this->get(ResultResource::getUrl())->assertOk();
});
