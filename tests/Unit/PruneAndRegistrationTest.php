<?php

use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\OpenAiEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

describe('pruning old answers', function () {
    beforeEach(function () {
        $brand = $this->createBrand(['name' => 'Acme']);
        $prompt = $brand->prompts()->create(['text' => 'Best CRM?']);
        $run = Run::query()->create(['brand_id' => $brand->id, 'results_total' => 2]);

        $make = fn (int $daysAgo) => tap(Result::query()->create([
            'run_id' => $run->id, 'brand_id' => $brand->id, 'prompt_id' => $prompt->id,
            'engine' => 'openai', 'model' => 'gpt-6-luna', 'status' => ResultStatus::Success,
            'answer' => 'Acme is great.', 'brand_mentioned' => true, 'brand_position' => 1, 'cost_usd' => 0.01,
            'ran_at' => now()->subDays($daysAgo),
        ]), fn ($result) => $result->mentions()->create(['subject_type' => 'brand', 'subject_id' => $brand->id, 'name_matched' => 'Acme', 'position' => 1, 'count' => 1, 'snippet' => 'Acme is great.', 'sentiment' => 'positive']));

        $this->old = $make(400);
        $this->recent = $make(10);
    });

    it('removes the text of old answers but keeps their metrics', function () {
        $this->artisan('ai-visibility:prune')->assertSuccessful();

        $old = $this->old->fresh();
        $mention = $old->mentions()->sole();

        expect($old->answer)->toBeNull()
            ->and($old->brand_mentioned)->toBeTrue()
            ->and($old->brand_position)->toBe(1)
            ->and((float) $old->cost_usd)->toBe(0.01)
            ->and($mention->snippet)->toBeNull()
            ->and($mention->sentiment)->toBe('positive')
            ->and($this->recent->fresh()->answer)->toBe('Acme is great.');
    });

    it('keeps everything when the limit is 0, and can count first', function () {
        $this->artisan('ai-visibility:prune', ['--dry-run' => true])
            ->expectsOutputToContain('1 answers older than 365 days would be removed')
            ->assertSuccessful();
        expect($this->old->fresh()->answer)->not->toBeNull();

        app(Settings::class)->set(['data' => ['keep_answers_days' => 0]]);
        $this->artisan('ai-visibility:prune')->assertSuccessful();

        expect($this->old->fresh()->answer)->not->toBeNull();
    });

    it('is scheduled daily', function () {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->description;

        expect($events)->toContain('ai-visibility:prune');
    });
});

describe('registering engines outside a panel', function () {
    it('registers engines and keyword sources from a service provider, even before the registry exists', function () {
        $engine = new class extends OpenAiEngine
        {
            public function key(): string
            {
                return 'custom_llm';
            }
        };

        // Before the registry is created (like AppServiceProvider::register()).
        app()->forgetInstance(EngineRegistry::class);
        AiVisibilityPlugin::registerEngine($engine);
        AiVisibilityPlugin::removeEngine('grok');

        expect(app(EngineRegistry::class)->has('custom_llm'))->toBeTrue()
            ->and(app(EngineRegistry::class)->has('grok'))->toBeFalse();

        // After it exists (like a queue worker that already used it).
        AiVisibilityPlugin::registerEngine(new class extends OpenAiEngine
        {
            public function key(): string
            {
                return 'another_llm';
            }
        });

        expect(app(EngineRegistry::class)->has('another_llm'))->toBeTrue();
    });
});
