<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertEvaluator;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertType;
use IsrarMinhas\FilamentAiVisibility\Alerts\StallWatcher;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\RunStatus;
use IsrarMinhas\FilamentAiVisibility\Models\AlertEvent;
use IsrarMinhas\FilamentAiVisibility\Models\AlertRule;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportBuilder;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportMail;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportSender;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

function answerAt($test, $prompt, bool $mentioned, $ranAt, string $engine = 'openai', array $competitors = []): Result
{
    $test->run ??= Run::query()->create(['brand_id' => $test->brand->id, 'results_total' => 0]);

    $result = Result::query()->create([
        'run_id' => $test->run->id, 'brand_id' => $test->brand->id, 'prompt_id' => $prompt->id, 'engine' => $engine,
        'status' => ResultStatus::Success, 'brand_mentioned' => $mentioned, 'brand_position' => $mentioned ? 1 : null, 'ran_at' => $ranAt,
    ]);

    if ($mentioned) {
        $result->mentions()->create(['subject_type' => 'brand', 'subject_id' => $test->brand->id, 'name_matched' => 'Acme', 'position' => 1]);
    }

    foreach ($competitors as $competitor) {
        $result->mentions()->create(['subject_type' => 'competitor', 'subject_id' => $competitor->id, 'name_matched' => $competitor->name, 'position' => 2]);
    }

    return $result;
}

/**
 * Start a new run: the answers stored next belong to it.
 */
function newRun($test, $brand = null, RunStatus $status = RunStatus::Completed): Run
{
    return $test->run = Run::query()->create(['brand_id' => ($brand ?? $test->brand)->id, 'status' => $status, 'results_total' => 0]);
}

/**
 * Store a run with one answer per sample for the test prompt.
 *
 * @param  array<bool>  $samples
 */
function runWith($test, array $samples, $ranAt, RunStatus $status = RunStatus::Completed): void
{
    newRun($test, status: $status);

    foreach ($samples as $mentioned) {
        answerAt($test, $test->prompt, $mentioned, $ranAt);
    }
}

/**
 * Visibility falls from 100% to 0% for a brand.
 */
function dropFor($brand, $prompt): void
{
    $run = Run::query()->create(['brand_id' => $brand->id, 'results_total' => 0]);

    foreach ([[true, now()->subDays(10)], [false, now()->subDay()]] as [$mentioned, $ranAt]) {
        Result::query()->create([
            'run_id' => $run->id, 'brand_id' => $brand->id, 'prompt_id' => $prompt->id, 'engine' => 'openai',
            'status' => ResultStatus::Success, 'brand_mentioned' => $mentioned, 'ran_at' => $ranAt,
        ]);
    }
}

function rule(AlertType $type, array $config = [], $brand = null, array $channels = ['database']): AlertRule
{
    return AlertRule::query()->create(['type' => $type, 'brand_id' => $brand?->id, 'config' => $config, 'channels' => $channels]);
}

beforeEach(function () {
    Mail::fake();
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->globex = $this->brand->competitors()->create(['name' => 'Globex']);
    $this->prompt = $this->brand->prompts()->create(['text' => 'Best CRM?']);
    $this->evaluator = app(AlertEvaluator::class);
});

describe('alert rules', function () {
    it('alerts on a visibility drop, once per episode', function () {
        rule(AlertType::VisibilityDrop, ['days' => 7, 'points' => 20], $this->brand);

        foreach (range(1, 4) as $i) {
            answerAt($this, $this->prompt, true, now()->subDays(10));
        }
        answerAt($this, $this->prompt, true, now()->subDay());
        answerAt($this, $this->prompt, false, now()->subDay());

        expect($this->evaluator->evaluate($this->brand))->toBe(1)
            ->and($this->evaluator->evaluate($this->brand))->toBe(0);

        $event = AlertEvent::query()->first();

        expect($event->title)->toBe('Acme: visibility dropped to 50%')
            ->and($event->type)->toBe('visibility_drop')
            ->and($event->brand_id)->toBe($this->brand->id)
            ->and($event->alert_rule_id)->not->toBeNull();
    });

    it('stays quiet when visibility holds', function () {
        rule(AlertType::VisibilityDrop, ['days' => 7, 'points' => 10], $this->brand);
        answerAt($this, $this->prompt, true, now()->subDays(10));
        answerAt($this, $this->prompt, true, now()->subDay());

        expect($this->evaluator->evaluate($this->brand))->toBe(0);
    });

    it('alerts when a competitor overtakes the brand', function () {
        rule(AlertType::CompetitorOvertakes, [], $this->brand);
        answerAt($this, $this->prompt, false, now(), competitors: [$this->globex]);
        answerAt($this, $this->prompt, true, now(), competitors: [$this->globex]);

        $this->evaluator->evaluate($this->brand);

        expect(AlertEvent::query()->value('title'))->toBe('Acme: Globex is ahead');
    });

    it('alerts on new direct competitors', function () {
        $rule = rule(AlertType::NewCompetitor, [], $this->brand);
        $rule->forceFill(['created_at' => now()->subDay()])->save();

        Candidate::query()->create(['brand_id' => $this->brand->id, 'key' => 'name:hooli', 'kind' => 'name', 'name' => 'Hooli', 'answers' => 4, 'status' => 'classified', 'label' => CompetitorLabel::DirectCompetitor, 'classified_at' => now()]);
        Candidate::query()->create(['brand_id' => $this->brand->id, 'key' => 'domain:g2.com', 'kind' => 'domain', 'name' => 'g2.com', 'status' => 'classified', 'label' => CompetitorLabel::ReviewComparison, 'classified_at' => now()]);

        $this->evaluator->evaluate($this->brand, only: [AlertType::NewCompetitor]);

        expect(AlertEvent::query()->value('title'))->toBe('Acme: 1 new direct competitor found')
            ->and(AlertEvent::query()->value('body'))->toContain('Hooli (in 4 answers)');
    });

    it('alerts when a prompt stops mentioning the brand', function () {
        rule(AlertType::PromptLost, ['previous' => 2], $this->brand);
        newRun($this);
        answerAt($this, $this->prompt, true, now()->subDays(3));
        newRun($this);
        answerAt($this, $this->prompt, true, now()->subDays(2));
        newRun($this);
        answerAt($this, $this->prompt, false, now()->subDay());
        answerAt($this, $this->prompt, false, now()->subDay(), 'gemini');

        $this->evaluator->evaluate($this->brand);

        expect(AlertEvent::query()->value('body'))->toBe('"Best CRM?" on OpenAI (ChatGPT)');
    });

    it('alerts on negative sentiment only with enough analysed mentions', function () {
        rule(AlertType::NegativeSentiment, ['percent' => 40], $this->brand);

        foreach ([true, true, false, false, false] as $negative) {
            answerAt($this, $this->prompt, true, now())->mentions()->where('subject_type', 'brand')->update(['sentiment' => $negative ? 'negative' : 'positive']);
        }

        $this->evaluator->evaluate($this->brand);

        expect(AlertEvent::query()->value('title'))->toBe('Acme: 40% of mentions are negative');
    });

    it('alerts on budget use once per month', function () {
        app(Settings::class)->set(['budget' => ['monthly_usd' => 10]]);
        rule(AlertType::BudgetThreshold, ['percent' => 80])->forceFill(['cooldown_hours' => 1])->save();
        Usage::query()->create(['engine' => 'openai', 'purpose' => 'tracking', 'cost_usd' => 8.5, 'created_at' => now()]);

        expect($this->evaluator->evaluate($this->brand))->toBe(1);

        $this->travel(2)->hours();
        expect($this->evaluator->evaluate($this->brand))->toBe(0)
            ->and(AlertEvent::query()->value('title'))->toBe('85% of the monthly AI Visibility budget used');
    });

    it('alerts on failed runs', function () {
        rule(AlertType::RunFailed, ['percent' => 50], $this->brand);
        $run = Run::query()->create(['brand_id' => $this->brand->id, 'status' => RunStatus::Partial, 'results_total' => 4, 'results_done' => 1, 'results_skipped' => 3]);

        expect($this->evaluator->evaluate($this->brand, $run))->toBe(1)
            ->and(AlertEvent::query()->value('title'))->toBe('Run for Acme: Partly completed');
    });

    it('sends only to the rule\'s channels, and ignores inactive rules and other brands', function () {
        Http::fake();
        app(Settings::class)->set(['alerts' => ['slack_webhook' => 'https://hooks.slack.com/x', 'emails' => ['ops@example.com']]]);
        $other = $this->createBrand(['name' => 'Other', 'domains' => ['other.com']]);

        rule(AlertType::CompetitorOvertakes, [], $this->brand, ['slack']);
        rule(AlertType::CompetitorOvertakes, [], $other, ['mail']);
        rule(AlertType::CompetitorOvertakes, [], $this->brand, ['mail'])->update(['is_active' => false]);
        answerAt($this, $this->prompt, false, now(), competitors: [$this->globex]);

        expect($this->evaluator->evaluate($this->brand))->toBe(1);

        Http::assertSentCount(1);
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
    });
});

describe('stall watcher', function () {
    it('alerts every tenant when the queue worker stops, at most every 6 hours', function () {
        app(Settings::class)->record();
        Heartbeat::beat(SystemHealth::queueHeartbeatName());
        expect(app(StallWatcher::class)->checkQueue())->toBeFalse();

        $this->travel(2)->hours();
        expect(app(StallWatcher::class)->checkQueue())->toBeTrue();
        app(StallWatcher::class)->checkQueue();

        expect(AlertEvent::query()->where('type', 'system_stalled')->count())->toBe(1);
    });

    it('alerts when the scheduler stops, checked from the panel', function () {
        Heartbeat::beat(SystemHealth::SCHEDULER);
        $this->travel(3)->hours();

        app(StallWatcher::class)->checkScheduler();

        expect(AlertEvent::query()->value('title'))->toBe('The Laravel scheduler has stopped');
    });

    it('records always-on engine alerts in the inbox', function () {
        app(\IsrarMinhas\FilamentAiVisibility\Engines\EngineManager::class)->pause('openai', \IsrarMinhas\FilamentAiVisibility\Enums\PauseReason::InvalidKey);

        expect(AlertEvent::query()->value('type'))->toBe('engine_paused');
    });
});

describe('scheduled reports', function () {
    beforeEach(function () {
        answerAt($this, $this->prompt, true, now()->subDay(), competitors: [$this->globex]);
        $this->schedule = ReportSchedule::query()->create([
            'brand_id' => $this->brand->id, 'name' => 'Weekly', 'frequency' => 'weekly',
            'recipients' => ['ceo@example.com', 'team@example.com'], 'next_send_at' => now()->subMinute(),
        ]);
    });

    it('builds the report data', function () {
        $report = app(ReportBuilder::class)->forSchedule($this->schedule);

        expect($report['summary']['visibility'])->toBe(100.0)
            ->and($report['leaderboard']->pluck('name')->all())->toContain('Acme', 'Globex')
            ->and($report['days'])->toBe(7);
    });

    it('renders the email', function () {
        $html = (new ReportMail(app(ReportBuilder::class)->forSchedule($this->schedule)))->render();

        expect($html)->toContain('AI visibility report')->toContain('Acme')->toContain('Competitors');
    });

    it('sends due reports and schedules the next one', function () {
        $this->artisan('ai-visibility:send-reports')->assertSuccessful();

        Mail::assertSent(ReportMail::class, fn (ReportMail $mail) => $mail->hasTo('ceo@example.com') && $mail->hasTo('team@example.com'));

        $this->schedule->refresh();
        expect($this->schedule->last_sent_at)->not->toBeNull()
            ->and($this->schedule->next_send_at->isMonday())->toBeTrue()
            ->and($this->schedule->next_send_at->isFuture())->toBeTrue();
    });

    it('records failures and retries later', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        expect(app(ReportSender::class)->send($this->schedule, scheduled: true))->toBeFalse()
            ->and($this->schedule->fresh()->last_error)->toBe('SMTP down')
            ->and($this->schedule->fresh()->next_send_at->isFuture())->toBeTrue()
            ->and($this->schedule->fresh()->is_active)->toBeTrue()
            ->and(AlertEvent::query()->count())->toBe(0);
    });

    it('only attaches PDFs when dompdf is installed', function () {
        $mail = new ReportMail(app(ReportBuilder::class)->forSchedule($this->schedule), attachPdf: true);

        expect($mail->attachments())->toHaveCount(ReportMail::canMakePdf() ? 1 : 0);
    });
});

describe('all-brands alert rules', function () {
    beforeEach(function () {
        $this->other = $this->createBrand(['name' => 'Other', 'domains' => ['other.com']]);
        $this->otherPrompt = $this->other->prompts()->create(['text' => 'Best ERP?']);
    });

    it('keeps the episode and cooldown per brand', function () {
        rule(AlertType::VisibilityDrop, ['days' => 7, 'points' => 20]);
        dropFor($this->brand, $this->prompt);
        dropFor($this->other, $this->otherPrompt);

        expect($this->evaluator->evaluate($this->brand))->toBe(1)
            ->and($this->evaluator->evaluate($this->other))->toBe(1)
            ->and($this->evaluator->evaluate($this->brand))->toBe(0)
            ->and($this->evaluator->evaluate($this->other))->toBe(0)
            ->and(AlertEvent::query()->pluck('brand_id')->sort()->values()->all())->toBe(collect([$this->brand->id, $this->other->id])->sort()->values()->all());
    });

    it('falls back to the rule cooldown when the inbox could not be written', function () {
        rule(AlertType::VisibilityDrop, ['days' => 7, 'points' => 20]);
        dropFor($this->brand, $this->prompt);
        AlertEvent::creating(fn () => false);

        expect($this->evaluator->evaluate($this->brand))->toBe(1)
            ->and($this->evaluator->evaluate($this->brand))->toBe(0)
            ->and(AlertEvent::query()->count())->toBe(0);
    });

    it('finds new competitors per brand', function () {
        rule(AlertType::NewCompetitor)->forceFill(['created_at' => now()->subDay()])->save();

        $candidate = fn ($brand, $name) => Candidate::query()->create(['brand_id' => $brand->id, 'key' => 'name:' . strtolower($name), 'kind' => 'name', 'name' => $name, 'answers' => 2, 'status' => 'classified', 'label' => CompetitorLabel::DirectCompetitor, 'classified_at' => now()->subHour()]);
        $candidate($this->brand, 'Hooli');
        $candidate($this->other, 'Initech');

        expect($this->evaluator->evaluate($this->brand, only: [AlertType::NewCompetitor]))->toBe(1)
            ->and($this->evaluator->evaluate($this->other, only: [AlertType::NewCompetitor]))->toBe(1)
            ->and(AlertEvent::query()->orderBy('id')->pluck('title')->all())->toBe(['Acme: 1 new direct competitor found', 'Other: 1 new direct competitor found']);
    });
});

describe('prompt lost with several samples', function () {
    beforeEach(function () {
        rule(AlertType::PromptLost, ['previous' => 1], $this->brand);
    });

    it('ignores one sample without the brand when another has it', function () {
        runWith($this, [true, true], now()->subDays(2));
        runWith($this, [false, true], now()->subDay());

        expect($this->evaluator->evaluate($this->brand))->toBe(0);
    });

    it('fires when every sample of the latest run misses the brand', function () {
        runWith($this, [false, true], now()->subDays(2));
        runWith($this, [false, false], now()->subDay());

        expect($this->evaluator->evaluate($this->brand))->toBe(1);
    });

    it('does not fire when the previous run also missed the brand', function () {
        runWith($this, [false, false], now()->subDays(2));
        runWith($this, [false, false], now()->subDay());

        expect($this->evaluator->evaluate($this->brand))->toBe(0);
    });

    it('waits for a run in progress to finish', function () {
        runWith($this, [true, true], now()->subDays(2));
        runWith($this, [false], now()->subHour(), RunStatus::Running);

        expect($this->evaluator->evaluate($this->brand))->toBe(0);
    });
});

describe('queue stall on a fresh install', function () {
    it('waits 30 minutes after install before reporting a queue that never ran', function () {
        app(Settings::class)->record();

        expect(app(StallWatcher::class)->checkQueue())->toBeFalse();

        $this->travel(31)->minutes();
        expect(app(StallWatcher::class)->checkQueue())->toBeTrue()
            ->and(AlertEvent::query()->value('body'))->toContain('No queued job has ever been processed');
    });
});

describe('report schedules', function () {
    beforeEach(function () {
        // A Monday.
        $this->travelTo(CarbonImmutable::parse('2026-09-07 08:00:00'));
        $this->schedule = ReportSchedule::query()->create([
            'brand_id' => $this->brand->id, 'name' => 'Weekly', 'frequency' => 'weekly',
            'recipients' => ['ceo@example.com'], 'next_send_at' => now()->subMinute(),
        ]);
    });

    it('covers the 7 whole days before the send day', function () {
        // The send day itself is left out.
        answerAt($this, $this->prompt, true, now()->subHour());
        answerAt($this, $this->prompt, false, now()->subDays(3));

        $report = app(ReportBuilder::class)->forSchedule($this->schedule);

        expect($report['from']->toDateTimeString())->toBe('2026-08-31 00:00:00')
            ->and($report['until']->toDateTimeString())->toBe('2026-09-06 23:59:59')
            ->and($report['days'])->toBe(7)
            ->and($report['summary']['answers'])->toBe(1);
    });

    it('covers the previous calendar month for monthly reports', function () {
        $this->schedule->update(['frequency' => 'monthly']);
        $sendDay = CarbonImmutable::parse('2026-03-01 08:00');

        [$from, $until] = $this->schedule->period($sendDay);

        expect($from->toDateTimeString())->toBe('2026-02-01 00:00:00')
            ->and($until->toDateTimeString())->toBe('2026-02-28 23:59:59')
            ->and($this->schedule->periodDays($sendDay))->toBe(28);
    });

    it('moves the next send when the frequency changes', function () {
        $this->schedule->update(['frequency' => 'monthly']);

        expect($this->schedule->fresh()->next_send_at->toDateTimeString())->toBe('2026-10-01 08:00:00');

        $this->schedule->update(['name' => 'Renamed']);
        expect($this->schedule->fresh()->next_send_at->toDateTimeString())->toBe('2026-10-01 08:00:00');
    });

    it('claims a due report so only one server sends it', function () {
        $first = ReportSchedule::query()->find($this->schedule->id);
        $second = ReportSchedule::query()->find($this->schedule->id);

        expect(app(ReportSender::class)->sendDue($first))->toBeTrue()
            ->and(app(ReportSender::class)->sendDue($second))->toBeNull();

        Mail::assertSentCount(1);
    });

    it('pauses a report after 3 failures in a row and alerts once', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
        $sender = app(ReportSender::class);

        foreach (range(1, 3) as $attempt) {
            expect($sender->send($this->schedule, scheduled: true))->toBeFalse();
        }

        expect($this->schedule->fresh()->is_active)->toBeFalse()
            ->and(AlertEvent::query()->where('type', 'report_failed')->count())->toBe(1)
            ->and(AlertEvent::query()->value('title'))->toBe('Report "Weekly" was paused');

        // Turning it back on starts the count again.
        $this->schedule->update(['is_active' => true]);
        expect($this->schedule->failureCount())->toBe(0)
            ->and($this->schedule->fresh()->failures)->toBe(0);
    });

    it('does not count failed "Send now" attempts or move the send day', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
        $this->schedule->update(['next_send_at' => '2026-09-14 08:00:00']);

        foreach (range(1, 4) as $attempt) {
            expect(app(ReportSender::class)->send($this->schedule))->toBeFalse();
        }

        $schedule = $this->schedule->fresh();

        expect($schedule->is_active)->toBeTrue()
            ->and($schedule->failureCount())->toBe(0)
            ->and($schedule->last_error)->toBe('SMTP down')
            ->and($schedule->next_send_at->toDateTimeString())->toBe('2026-09-14 08:00:00')
            ->and(AlertEvent::query()->count())->toBe(0);
    });

    it('keeps the send day after a successful "Send now"', function () {
        $this->schedule->update(['next_send_at' => '2026-09-14 08:00:00']);

        expect(app(ReportSender::class)->send($this->schedule))->toBeTrue()
            ->and($this->schedule->fresh()->next_send_at->toDateTimeString())->toBe('2026-09-14 08:00:00')
            ->and($this->schedule->fresh()->last_sent_at)->not->toBeNull();
    });

    it('sends from the next regular day when turned back on', function () {
        $this->schedule->update(['is_active' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00'));

        $this->schedule->update(['is_active' => true]);

        expect($this->schedule->fresh()->next_send_at->toDateTimeString())->toBe('2026-09-28 08:00:00');
    });

    it('sends the same day when created on the send day before 08:00', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 06:30:00'));

        $weekly = ReportSchedule::query()->create(['brand_id' => $this->brand->id, 'name' => 'Early', 'frequency' => 'weekly', 'recipients' => ['a@example.com']]);

        expect($weekly->next_send_at->toDateTimeString())->toBe('2026-09-14 08:00:00')
            ->and($weekly->nextSendAfter(CarbonImmutable::parse('2026-09-14 08:00:00'))->toDateTimeString())->toBe('2026-09-21 08:00:00')
            ->and($weekly->nextSendAfter(CarbonImmutable::parse('2026-09-13 23:00:00'))->toDateTimeString())->toBe('2026-09-14 08:00:00');
    });

    it('reads the failure count on installs without the failures column', function () {
        $reset = fn () => Closure::bind(fn () => static::$hasFailuresColumn = null, null, ReportSchedule::class)();

        Schema::table((new ReportSchedule)->getTable(), fn ($table) => $table->dropColumn('failures'));
        $reset();

        try {
            $schedule = ReportSchedule::query()->find($this->schedule->id);

            expect($schedule->failures)->toBeNull()
                ->and($schedule->failureCount())->toBe(0)
                ->and($schedule->recordFailure())->toBe(1)
                ->and(ReportSchedule::query()->find($this->schedule->id)->failureCount())->toBe(1);
        } finally {
            $reset();
        }
    });

    it('skips reports for inactive brands', function () {
        $this->brand->update(['is_active' => false]);

        $this->artisan('ai-visibility:send-reports')->assertSuccessful();

        Mail::assertNothingSent();
    });
});
