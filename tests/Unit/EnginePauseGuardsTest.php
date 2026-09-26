<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\EngineStatus;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Models\EngineState;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

describe('pauses that wait for a person', function () {
    it('keeps a manual or budget pause whatever fails afterwards', function (PauseReason $held, PauseReason $failure, int $times) {
        $engines = app(EngineManager::class);
        $engines->pause('openai', $held);

        foreach (range(1, $times) as $ignored) {
            $engines->recordFailure('openai', $failure, 'late result', retryAfter: 10);
        }

        $state = $engines->state('openai');

        expect($state->status)->toBe(EngineStatus::Paused)
            ->and($state->reason)->toBe($held)
            ->and($state->next_probe_at)->toBeNull()
            ->and($state->resume_after)->toBeNull()
            ->and($state->last_error['message'])->toBe('late result')
            ->and($engines->dueForProbe())->toBe($held === PauseReason::Budget ? ['openai'] : []);
    })->with([
        'manual, out of credits' => [PauseReason::Manual, PauseReason::InsufficientCredits, 1],
        'manual, outage' => [PauseReason::Manual, PauseReason::ProviderOutage, 5],
        'manual, rate limited' => [PauseReason::Manual, PauseReason::RateLimited, 5],
        'manual, bad key' => [PauseReason::Manual, PauseReason::InvalidKey, 1],
        'budget, outage' => [PauseReason::Budget, PauseReason::ProviderOutage, 5],
        'invalid key, rate limited' => [PauseReason::InvalidKey, PauseReason::RateLimited, 5],
    ]);

    it('keeps a manual pause when the key goes missing', function () {
        $engines = app(EngineManager::class);
        $engines->pause('openai', PauseReason::Manual);

        expect($engines->isUsable('openai'))->toBeFalse()
            ->and($engines->state('openai')->reason)->toBe(PauseReason::Manual);

        // Adding the key does not lift the manual pause.
        app(KeyResolver::class)->store('openai', 'sk');
        expect($engines->isUsable('openai'))->toBeFalse();
    });

    it('never degrades an engine paused for an outage', function () {
        config(['ai-visibility.reliability.failure_threshold' => 1]);
        $engines = app(EngineManager::class);

        $engines->recordFailure('openai', PauseReason::ProviderOutage);
        $engines->recordFailure('openai', PauseReason::RateLimited);

        expect($engines->state('openai')->status)->toBe(EngineStatus::Paused)
            ->and($engines->state('openai')->reason)->toBe(PauseReason::ProviderOutage);
    });
});

describe('outage back-off', function () {
    it('schedules one pause per outage, however many requests fail during it', function () {
        $engines = app(EngineManager::class);

        foreach (range(1, 12) as $ignored) {
            $engines->recordFailure('openai', PauseReason::ProviderOutage, 'down');
        }

        $state = $engines->state('openai');

        expect($state->reason)->toBe(PauseReason::ProviderOutage)
            ->and($state->last_error['outage_pauses'])->toBe(1)
            ->and((int) round(now()->diffInMinutes($state->next_probe_at)))->toBe(15);
    });

    it('counts failures per reason', function () {
        $engines = app(EngineManager::class);

        foreach (range(1, 4) as $ignored) {
            $engines->recordFailure('openai', PauseReason::ProviderOutage);
        }

        $engines->recordFailure('openai', PauseReason::RateLimited);
        expect($engines->state('openai')->consecutive_failures)->toBe(1)
            ->and($engines->state('openai')->status)->toBe(EngineStatus::Degraded);

        // The outage count started again, so one more timeout does not trip the breaker.
        $engines->recordFailure('openai', PauseReason::ProviderOutage);
        expect($engines->state('openai')->status)->toBe(EngineStatus::Degraded);
    });
});

describe('probe', function () {
    it('switches to the reason the engine now fails for', function () {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Service unavailable']], 503)]);
        app(KeyResolver::class)->store('openai', 'sk');
        $engines = app(EngineManager::class);

        $engines->pause('openai', PauseReason::InsufficientCredits);
        $this->travel(7)->hours();

        $this->artisan('ai-visibility:probe')->assertSuccessful();

        $state = $engines->state('openai');
        expect($state->reason)->toBe(PauseReason::ProviderOutage)
            ->and($state->next_probe_at?->isFuture())->toBeTrue();
    });
});

describe('engine commands', function () {
    it('lists engines without creating their state', function () {
        $this->artisan('ai-visibility:engines')->assertSuccessful();
        $this->artisan('ai-visibility:health');

        expect(EngineState::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('rejects an unknown tenant', function (string $command) {
        Tenancy::as('team-a', fn () => app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]));

        $this->artisan($command, ['--tenant' => 'team-typo'])
            ->expectsOutputToContain('Unknown tenant [team-typo]')
            ->assertFailed();

        $this->artisan('ai-visibility:engines', ['--tenant' => 'team-a'])
            ->expectsOutputToContain('team-a')
            ->assertSuccessful();

        expect(EngineState::query()->withoutGlobalScopes()->count())->toBe(0);
    })->with(['ai-visibility:engines', 'ai-visibility:health']);
});
