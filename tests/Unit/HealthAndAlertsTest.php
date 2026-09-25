<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Jobs\QueueHeartbeat;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Support\Health\CheckResult;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

describe('health', function () {
    it('fails on a sync queue and missing heartbeats', function () {
        config(['queue.default' => 'sync']);
        $health = app(SystemHealth::class);

        expect($health->queueDriver()->status)->toBe(CheckResult::FAILED)
            ->and($health->queueWorker()->status)->toBe(CheckResult::FAILED)
            ->and($health->scheduler()->status)->toBe(CheckResult::FAILED)
            ->and($health->hasBlockingFailures())->toBeTrue();
    });

    it('passes with fresh heartbeats and warns on stale ones', function () {
        Heartbeat::beat(SystemHealth::SCHEDULER);
        (new QueueHeartbeat)->handle();
        $health = app(SystemHealth::class);

        expect($health->queueWorker()->ok())->toBeTrue()
            ->and($health->scheduler()->ok())->toBeTrue()
            ->and($health->hasBlockingFailures())->toBeFalse();

        $this->travel(10)->minutes();
        expect($health->scheduler()->status)->toBe(CheckResult::WARNING);

        $this->travel(2)->hours();
        expect($health->scheduler()->status)->toBe(CheckResult::FAILED);
    });

    it('registers the heartbeat schedule', function () {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->description;

        expect($events)->toContain('ai-visibility:scheduler-heartbeat', 'ai-visibility:queue-heartbeat');
    });

    it('reports through the health command', function () {
        Heartbeat::beat(SystemHealth::SCHEDULER);
        Heartbeat::beat(SystemHealth::queueHeartbeatName());

        $this->artisan('ai-visibility:health')->assertSuccessful();

        app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
        $this->artisan('ai-visibility:health')->assertFailed();
    });
});

describe('alerts', function () {
    it('sends engine pause alerts to Slack, email and the panel', function () {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $user = $this->createUser();

        app(Settings::class)->set(['alerts' => [
            'slack_webhook' => 'https://hooks.slack.com/services/T/B/X',
            'emails' => ['ops@example.com'],
            'user_ids' => [$user->id],
            'database' => true,
        ]]);

        app(EngineManager::class)->pause('openai', PauseReason::InsufficientCredits);

        Http::assertSent(fn ($request) => str_contains($request['text'], 'OpenAI (ChatGPT) paused: Out of credits'));

        $mail = app('mailer')->getSymfonyTransport()->messages();
        expect($mail)->toHaveCount(1)
            ->and($mail->first()->getOriginalMessage()->getSubject())->toBe('[AI Visibility] OpenAI (ChatGPT) paused: Out of credits')
            ->and($user->notifications()->count())->toBe(1);
    });

    it('sends alerts with the settings of the tenant the engine belongs to', function () {
        Http::fake(['*' => Http::response('ok')]);

        Tenancy::resolveUsing(fn () => 'team-a');
        app(Settings::class)->set(['alerts' => ['slack_webhook' => 'https://hooks.slack.com/team-a']]);

        Tenancy::resolveUsing(fn () => 'team-b');
        app(Settings::class)->set(['alerts' => ['slack_webhook' => 'https://hooks.slack.com/team-b']]);

        Tenancy::resolveUsing(fn () => 'team-a');
        app(EngineManager::class)->pause('gemini', PauseReason::InvalidKey);

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.com/team-a');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://hooks.slack.com/team-b');
    });

    it('never fails when a channel is broken', function () {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        app(Settings::class)->set(['alerts' => ['slack_webhook' => 'https://hooks.slack.com/x', 'user_ids' => [999]]]);

        app(EngineManager::class)->pause('openai', PauseReason::ProviderOutage);

        expect(app(EngineManager::class)->state('openai')->reason)->toBe(PauseReason::ProviderOutage);
    });
});
