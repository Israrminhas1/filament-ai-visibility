<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunFrequency;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunTrigger;
use IsrarMinhas\FilamentAiVisibility\Exceptions\RunNotStarted;
use IsrarMinhas\FilamentAiVisibility\Jobs\RunResultJob;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Runs\RunPlanner;
use IsrarMinhas\FilamentAiVisibility\Runs\RunProgress;
use IsrarMinhas\FilamentAiVisibility\Runs\RunSweeper;
use IsrarMinhas\FilamentAiVisibility\Support\CostEstimator;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Spend;

function openAiAnswer(string $text = 'Globex is fine, but Acme is best. Source: https://acme.com/crm', int $status = 200, array $error = [])
{
    return $status === 200
        ? Http::response([
            'model' => 'gpt-5-mini',
            'output' => [
                ['type' => 'web_search_call'],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => [['type' => 'url_citation', 'url' => 'https://globex.io/pricing', 'title' => 'Globex pricing']]]]],
            ],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ])
        : Http::response(['error' => $error], $status);
}

/**
 * Run queued RunResultJobs in-process, like a worker would.
 */
function work(): void
{
    foreach (Queue::pushedJobs()[RunResultJob::class] ?? [] as $pushed) {
        $job = $pushed['job']->withFakeQueueInteractions();
        $job->handle();
    }
}

beforeEach(function () {
    Queue::fake();
    $this->completeSetup();
    app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
    app(KeyResolver::class)->store('openai', 'sk-test');

    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com'], 'market' => 'United Kingdom']);
    $this->brand->competitors()->create(['name' => 'Globex', 'domains' => ['globex.io']]);
    $this->brand->prompts()->create(['text' => 'Best CRM for agencies?']);
    $this->brand->prompts()->create(['text' => 'Cheapest CRM?']);
});

describe('planning', function () {
    it('creates one result per prompt × engine × sample and queues them', function () {
        app(Settings::class)->set(['engines' => ['enabled' => ['openai', 'gemini']], 'runs' => ['samples' => 2]]);
        app(KeyResolver::class)->store('gemini', 'g');

        $run = app(RunPlanner::class)->start($this->brand);

        expect($run->results_total)->toBe(8)
            ->and($run->status)->toBe(RunStatus::Running)
            ->and($run->results()->count())->toBe(8)
            ->and($run->estimated_cost_usd)->toBeGreaterThan(0)
            ->and($this->brand->fresh()->last_run_at)->not->toBeNull();

        Queue::assertPushed(RunResultJob::class, 8);
    });

    it('refuses to start when a run would be wasteful or impossible', function (Closure $setup, string $message) {
        $setup($this);
        $runs = Run::query()->count();
        Queue::fake();

        expect(fn () => app(RunPlanner::class)->start($this->brand->fresh()))->toThrow(RunNotStarted::class, $message);
        expect(Run::query()->count())->toBe($runs);
        Queue::assertNothingPushed();
    })->with([
        'setup unfinished' => [fn () => app(Settings::class)->record()->forceFill(['setup_completed_at' => null])->save(), 'Finish the AI Visibility setup'],
        'kill switch' => [fn () => app(Settings::class)->setKillSwitch(true), 'Pause everything'],
        'no engines' => [fn () => app(Settings::class)->set(['engines' => ['enabled' => []]]), 'No engines are enabled'],
        'engines paused' => [fn () => app(EngineManager::class)->pause('openai', PauseReason::InvalidKey), 'Every enabled engine is paused'],
        'no prompts' => [fn ($test) => $test->brand->prompts()->update(['status' => 'paused']), 'no active prompts'],
        'over budget' => [fn () => app(Settings::class)->set(['budget' => ['monthly_usd' => 0.001]]), 'monthly budget is left'],
        'sync queue' => [fn () => config(['queue.default' => 'sync']), 'synchronously'],
        'runs per day' => [function ($test) {
            app(Settings::class)->set(['limits' => ['max_runs_per_brand_per_day' => 1]]);
            app(RunPlanner::class)->start($test->brand);
            Run::query()->delete();
            $test->brand->runs()->create(['results_total' => 0]);
        }, 'limit of 1 runs today'],
    ]);

    it('knows when scheduled runs are due', function () {
        $planner = app(RunPlanner::class);
        app(Settings::class)->set(['runs' => ['time' => '03:00']]);
        $this->travelTo(now()->setTime(10, 0));

        $this->brand->update(['run_frequency' => RunFrequency::Daily, 'last_run_at' => null]);
        expect($planner->isDue($this->brand))->toBeTrue();

        $this->brand->update(['last_run_at' => now()->setTime(3, 5)]);
        expect($planner->isDue($this->brand))->toBeFalse();

        $this->brand->update(['last_run_at' => now()->subDay()->setTime(3, 5)]);
        expect($planner->isDue($this->brand))->toBeTrue();

        $this->brand->update(['run_frequency' => RunFrequency::Weekly, 'last_run_at' => now()->subDays(3)]);
        expect($planner->isDue($this->brand))->toBeFalse();

        $this->brand->update(['last_run_at' => now()->subDays(8)]);
        expect($planner->isDue($this->brand))->toBeTrue();

        $this->brand->update(['run_frequency' => RunFrequency::Manual, 'last_run_at' => null]);
        expect($planner->isDue($this->brand))->toBeFalse();
    });
});

describe('running', function () {
    it('collects answers, detects mentions and sources, tracks cost and closes the run', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer()]);

        $run = app(RunPlanner::class)->start($this->brand, RunTrigger::Manual);
        work();

        $run->refresh();
        $result = Result::query()->with(['mentions', 'citations'])->first();

        expect($run->status)->toBe(RunStatus::Completed)
            ->and($run->results_done)->toBe(2)
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->cost_usd)->toBeGreaterThan(0)
            ->and($result->status)->toBe(ResultStatus::Success)
            ->and($result->brand_mentioned)->toBeTrue()
            ->and($result->brand_position)->toBe(2)
            ->and($result->brand_cited)->toBeTrue()
            ->and($result->mentions->pluck('name_matched')->all())->toBe(['Globex', 'Acme'])
            ->and($result->citations->pluck('domain')->all())->toBe(['globex.io', 'acme.com'])
            ->and($result->citations->firstWhere('domain', 'globex.io')->competitor_id)->not->toBeNull()
            ->and(Usage::query()->where('purpose', 'tracking')->count())->toBe(2)
            ->and(round(Usage::query()->sum('cost_usd'), 6))->toBe(round($run->cost_usd, 6));

        // The brand's market is sent as the search location.
        Http::assertSent(fn ($request) => ($request['tools'][0]['user_location']['country'] ?? null) === 'GB');
    });

    it('pauses the engine on a bad key and skips the rest without calling it again', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer(status: 401, error: ['message' => 'Incorrect API key'])]);

        $run = app(RunPlanner::class)->start($this->brand);
        work();

        $run->refresh();

        expect(app(EngineManager::class)->state('openai')->reason)->toBe(PauseReason::InvalidKey)
            ->and($run->results_skipped)->toBe(2)
            ->and($run->status)->toBe(RunStatus::StoppedPaused)
            ->and(Result::query()->pluck('skip_reason')->unique()->all())->toBe(['engine_paused:invalid_key']);

        Http::assertSentCount(1);
    });

    it('pauses on exhausted credits and schedules an automatic check', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer(status: 429, error: ['code' => 'insufficient_quota', 'message' => 'You exceeded your current quota'])]);

        app(RunPlanner::class)->start($this->brand);
        work();

        $state = app(EngineManager::class)->state('openai');

        expect($state->reason)->toBe(PauseReason::InsufficientCredits)
            ->and($state->next_probe_at->isFuture())->toBeTrue();
        Http::assertSentCount(1);
    });

    it('retries temporary failures later instead of failing them', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer(status: 503, error: ['message' => 'overloaded'])]);

        app(RunPlanner::class)->start($this->brand);
        $job = Queue::pushedJobs()[RunResultJob::class][0]['job']->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased();
        expect(Result::query()->first()->status)->toBe(ResultStatus::Pending)
            ->and(app(EngineManager::class)->state('openai')->status)->toBe(EngineStatus::Active);
    });

    it('trips the circuit breaker after repeated outages', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer(status: 503, error: ['message' => 'overloaded'])]);
        $engines = app(EngineManager::class);

        foreach (range(1, 5) as $i) {
            $engines->recordFailure('openai', PauseReason::ProviderOutage, 'down');
        }

        $state = $engines->state('openai');

        expect($state->status)->toBe(EngineStatus::Paused)
            ->and($state->reason)->toBe(PauseReason::ProviderOutage)
            ->and((int) round(now()->diffInMinutes($state->next_probe_at)))->toBe(15);
    });

    it('slows down on rate limits before pausing', function () {
        $engines = app(EngineManager::class);
        app(Settings::class)->set(['engines' => ['requests_per_minute' => 20]]);

        $engines->recordFailure('openai', PauseReason::RateLimited, 'slow down');

        expect($engines->state('openai')->status)->toBe(EngineStatus::Degraded)
            ->and($engines->requestsPerMinute('openai'))->toBe(10)
            ->and($engines->isUsable('openai'))->toBeTrue();
    });

    it('stops spending when the monthly budget is used up', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer()]);
        // Enough budget to start (estimate) but not for both answers at real cost.
        config(['ai-visibility.estimated_cost_per_result.openai' => 0.0001]);
        app(Settings::class)->set(['budget' => ['monthly_usd' => 0.005]]);

        $run = app(RunPlanner::class)->start($this->brand);
        work();

        $run->refresh();

        expect($run->results_done)->toBe(1)
            ->and($run->results_skipped)->toBe(1)
            ->and($run->status)->toBe(RunStatus::Partial)
            ->and(app(EngineManager::class)->state('openai')->reason)->toBe(PauseReason::Budget);
        Http::assertSentCount(1);
    });

    it('skips everything when the kill switch is turned on mid-run', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer()]);

        app(RunPlanner::class)->start($this->brand);
        app(Settings::class)->setKillSwitch(true);
        work();

        expect(Result::query()->pluck('skip_reason')->unique()->all())->toBe(['kill_switch']);
        Http::assertNothingSent();
    });

    it('retries skipped answers once the engine is back', function () {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'bad key']], 401)
            ->whenEmpty(openAiAnswer())]);

        $run = app(RunPlanner::class)->start($this->brand);
        work();
        expect($run->refresh()->status)->toBe(RunStatus::StoppedPaused);

        app(EngineManager::class)->resume('openai');
        Queue::fake();

        expect(app(RunPlanner::class)->retrySkipped($run))->toBe(2);
        work();

        expect($run->refresh()->status)->toBe(RunStatus::Completed)
            ->and($run->results_done)->toBe(2)
            ->and($run->results_skipped)->toBe(0);
    });
});

describe('commands', function () {
    it('starts due runs for every tenant', function () {
        $this->brand->update(['run_frequency' => RunFrequency::Daily, 'last_run_at' => null]);

        $this->artisan('ai-visibility:run --due')->assertSuccessful();

        expect(Run::query()->count())->toBe(1);
        Queue::assertPushed(RunResultJob::class, 2);
    });

    it('resumes engines when their automatic check passes', function () {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => []])]);
        $engines = app(EngineManager::class);
        $engines->pause('openai', PauseReason::InsufficientCredits, probeAt: now()->subMinute());

        $this->artisan('ai-visibility:probe')->assertSuccessful();

        expect($engines->state('openai')->status)->toBe(EngineStatus::Active);
    });

    it('keeps engines paused while the check still fails', function () {
        Http::fake(['api.openai.com/v1/models' => Http::response(['error' => ['code' => 'insufficient_quota']], 429)]);
        $engines = app(EngineManager::class);
        $engines->pause('openai', PauseReason::InsufficientCredits, probeAt: now()->subMinute());

        $this->artisan('ai-visibility:probe')->assertSuccessful();

        expect($engines->state('openai')->status)->toBe(EngineStatus::Paused)
            ->and($engines->state('openai')->next_probe_at->isFuture())->toBeTrue();
    });

    it('resumes budget-paused engines in a new month', function () {
        app(Settings::class)->set(['budget' => ['monthly_usd' => 1]]);
        Usage::query()->create(['engine' => 'openai', 'purpose' => 'tracking', 'cost_usd' => 5, 'created_at' => now()->subMonth()]);
        app(EngineManager::class)->pause('openai', PauseReason::Budget);

        $this->artisan('ai-visibility:probe')->assertSuccessful();

        expect(app(EngineManager::class)->state('openai')->status)->toBe(EngineStatus::Active);
    });
});

describe('reliability', function () {
    it('closes the run when a prompt is deleted mid-run', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer()]);

        $run = app(RunPlanner::class)->start($this->brand, RunTrigger::Manual);
        // Deleting a prompt deletes its results (cascade), so their jobs find nothing.
        $prompt = $this->brand->prompts()->orderByDesc('id')->first();
        Result::query()->where('prompt_id', $prompt->getKey())->delete();
        $prompt->delete();
        work();

        $run->refresh();

        expect($run->status)->toBe(RunStatus::Completed)
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->results_total)->toBe(1)
            ->and($run->results_done)->toBe(1);
    });

    it('fails answers whose prompt is gone instead of leaving them pending', function () {
        Http::fake();
        $run = app(RunPlanner::class)->start($this->brand);

        DB::statement('PRAGMA foreign_keys = OFF');
        $this->brand->prompts()->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        work();

        $run->refresh();

        expect($run->status)->toBe(RunStatus::Failed)
            ->and($run->results_failed)->toBe(2)
            ->and(Result::query()->pluck('status')->unique()->all())->toBe([ResultStatus::Failed]);
        Http::assertNothingSent();
    });

    it('closes runs whose queue jobs were lost', function () {
        $this->brand->update(['run_frequency' => RunFrequency::Manual]);
        $run = app(RunPlanner::class)->start($this->brand);

        $this->travel(5)->hours();
        expect(app(RunSweeper::class)->sweep())->toBe([]);

        // A result retried recently is still making progress.
        $this->travel(2)->hours();
        Result::query()->first()->touch();
        expect(app(RunSweeper::class)->sweep())->toBe([]);

        $this->travel(7)->hours();
        $this->artisan('ai-visibility:run --due')->assertSuccessful();

        $run->refresh();

        expect($run->finished_at)->not->toBeNull()
            ->and($run->status)->toBe(RunStatus::Failed)
            ->and($run->results_failed)->toBe(2)
            ->and(Result::query()->pluck('error')->unique()->all())->toBe([RunSweeper::LOST_JOB]);

        // A job that turns up late costs nothing.
        Http::fake();
        work();
        Http::assertNothingSent();
    });

    it('spreads jobs out at the engine rate and gives each its own retry window', function () {
        app(Settings::class)->set(['engines' => ['requests_per_minute' => 1]]);
        $this->brand->prompts()->create(['text' => 'CRM with invoicing?']);

        app(RunPlanner::class)->start($this->brand);

        $jobs = Queue::pushed(RunResultJob::class)->values();

        expect($jobs)->toHaveCount(3)
            ->and($jobs->map(fn ($job) => $job->delay)->all())->toBe([null, 60, 120])
            ->and($jobs[2]->retryUntil()->getTimestamp())->toBeGreaterThanOrEqual(now()->addSeconds(3600 + 120)->getTimestamp());

        // Never more than a day, however big the run.
        Queue::fake();
        RunResultJob::dispatchPaced(range(1, 2000), 'openai', null);

        expect(Queue::pushed(RunResultJob::class)->last()->retryFor)->toBe(86400);
    });

    it('never asks the engine twice for the same answer', function () {
        Http::fake(['api.openai.com/*' => openAiAnswer()]);
        $run = app(RunPlanner::class)->start($this->brand);

        $job = Queue::pushed(RunResultJob::class)->first()->withFakeQueueInteractions();
        $result = Result::query()->find($job->resultId);

        expect($job->timeout)->toBe(240);

        // Another worker is answering it right now.
        expect(app(RunProgress::class)->claim($result))->toBeTrue();
        $job->handle();

        Http::assertNothingSent();
        expect($result->fresh()->status)->toBe(ResultStatus::Running);

        // A claim abandoned by a crashed worker is taken over.
        $this->travel(6)->minutes();
        $job->handle();

        expect($result->fresh()->status)->toBe(ResultStatus::Success);

        // A duplicate of the job does nothing.
        $job->handle();

        Http::assertSentCount(1);
        expect($run->refresh()->results_done)->toBe(1);
    });

    it('counts runs in progress against the budget', function () {
        app(Settings::class)->set(['budget' => ['monthly_usd' => 1]]);
        $this->brand->runs()->create([
            'status' => RunStatus::Running,
            'results_total' => 10,
            'results_done' => 5,
            'estimated_cost_usd' => 1.6,
            'started_at' => now(),
        ]);

        $spend = app(Spend::class);

        expect($spend->committed())->toEqualWithDelta(0.8, 0.000001)
            ->and($spend->remaining())->toEqualWithDelta(0.2, 0.000001)
            ->and($spend->remaining(includeCommitted: false))->toEqualWithDelta(1.0, 0.000001)
            ->and($spend->overBudget())->toBeFalse();

        config(['ai-visibility.estimated_cost_per_result.openai' => 0.15]);

        expect(fn () => app(RunPlanner::class)->start($this->brand))->toThrow(RunNotStarted::class, 'after runs in progress');
    });

    it('stops starting scheduled runs once the budget is committed', function () {
        config(['ai-visibility.estimated_cost_per_result.openai' => 0.2]);
        app(Settings::class)->set(['budget' => ['monthly_usd' => 1]]);
        $this->brand->update(['run_frequency' => RunFrequency::Daily, 'last_run_at' => null]);

        foreach (['Globex', 'Initech'] as $name) {
            $brand = $this->createBrand(['name' => $name, 'run_frequency' => RunFrequency::Daily]);
            $brand->prompts()->create(['text' => 'Best CRM?']);
            $brand->prompts()->create(['text' => 'Cheapest CRM?']);
        }

        // Each run is estimated at $0.40: a third would take the committed total past $1.
        $this->artisan('ai-visibility:run --due')->assertSuccessful();

        expect(Run::query()->count())->toBe(2);
    });

    it('keeps starting other brands when one fails unexpectedly', function () {
        $this->brand->update(['run_frequency' => RunFrequency::Daily, 'last_run_at' => null]);
        $other = $this->createBrand(['name' => 'Globex', 'run_frequency' => RunFrequency::Daily]);
        $other->prompts()->create(['text' => 'Best CRM?']);

        $this->app->instance(RunPlanner::class, new class(app(Settings::class), app(EngineManager::class), app(Spend::class)) extends RunPlanner
        {
            public function start(Brand $brand, RunTrigger $trigger = RunTrigger::Manual, ?array $promptIds = null, ?int $userId = null): Run
            {
                if ($brand->name === 'Acme') {
                    throw new RuntimeException('Database hiccup');
                }

                return parent::start($brand, $trigger, $promptIds, $userId);
            }
        });

        $this->artisan('ai-visibility:run --due')
            ->expectsOutputToContain('Database hiccup')
            ->assertSuccessful();

        expect(Run::query()->pluck('brand_id')->all())->toBe([$other->getKey()]);
    });

    it('learns the cost per answer from recent real answers', function () {
        config(['ai-visibility.estimated_cost_per_result.openai' => 0.03]);
        $run = $this->brand->runs()->create(['results_total' => 0]);
        $prompt = $this->brand->prompts()->first();

        $answer = fn (float $cost, string $model) => Result::query()->create([
            'run_id' => $run->getKey(),
            'brand_id' => $this->brand->getKey(),
            'prompt_id' => $prompt->getKey(),
            'engine' => 'openai',
            'model' => $model,
            'status' => ResultStatus::Success,
            'cost_usd' => $cost,
        ]);

        foreach (range(1, 4) as $i) {
            $answer(0.07, 'gpt-a');
        }

        // Too few answers to trust yet.
        expect(CostEstimator::perResult('openai'))->toBe(0.03);

        $answer(0.07, 'gpt-a');

        expect(CostEstimator::perResult('openai'))->toEqualWithDelta(0.07, 0.000001)
            ->and(CostEstimator::costPerRun(2, ['openai']))->toEqualWithDelta(0.14, 0.000001);

        foreach (range(1, 5) as $i) {
            $answer(0.10, 'gpt-b');
        }

        expect(CostEstimator::perResult('openai', 'gpt-b'))->toEqualWithDelta(0.10, 0.000001)
            ->and(CostEstimator::perResult('openai', 'gpt-a'))->toEqualWithDelta(0.07, 0.000001)
            ->and(CostEstimator::perResult('openai', 'gpt-new'))->toEqualWithDelta(0.085, 0.000001)
            ->and(CostEstimator::costPerRun(1, ['openai'], 1, ['openai' => 'gpt-b']))->toEqualWithDelta(0.10, 0.000001);
    });
});
