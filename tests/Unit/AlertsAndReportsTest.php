<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        answerAt($this, $this->prompt, true, now()->subDays(3));
        answerAt($this, $this->prompt, true, now()->subDays(2));
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

        expect(app(ReportSender::class)->send($this->schedule))->toBeFalse()
            ->and($this->schedule->fresh()->last_error)->toBe('SMTP down')
            ->and(AlertEvent::query()->value('type'))->toBe('report_failed');
    });

    it('only attaches PDFs when dompdf is installed', function () {
        $mail = new ReportMail(app(ReportBuilder::class)->forSchedule($this->schedule), attachPdf: true);

        expect($mail->attachments())->toHaveCount(ReportMail::canMakePdf() ? 1 : 0);
    });
});
