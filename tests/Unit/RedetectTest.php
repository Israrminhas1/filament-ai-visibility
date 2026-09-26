<?php

use Illuminate\Support\Facades\Queue;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Jobs\RedetectBrandJob;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Runs\Redetector;

beforeEach(function () {
    // The real case: the legal name came from the website, answers use the everyday name.
    $this->brand = $this->createBrand(['name' => 'Nintendo Co., Ltd.', 'domains' => ['nintendo.com']]);
    $prompt = $this->brand->prompts()->create(['text' => 'Best console for kids?']);
    $run = Run::query()->create(['brand_id' => $this->brand->id, 'results_total' => 1]);

    $this->result = Result::query()->create([
        'run_id' => $run->id, 'brand_id' => $this->brand->id, 'prompt_id' => $prompt->id,
        'engine' => 'anthropic', 'model' => 'claude-sonnet-5', 'status' => ResultStatus::Success,
        'answer' => 'For young kids, the Nintendo Switch is easiest. A PlayStation 5 suits older kids.',
        'brand_mentioned' => false,
    ]);
    $this->result->citations()->create(['url' => 'https://www.nintendo.com/parental-controls', 'domain' => 'nintendo.com', 'position' => 1, 'is_brand' => false]);
});

it('corrects stored answers with the current names and domains', function () {
    $this->brand->competitors()->createQuietly(['name' => 'PlayStation']);

    expect(app(Redetector::class)->brand($this->brand))->toBe(1);

    $result = $this->result->fresh();

    expect($result->brand_mentioned)->toBeTrue()
        ->and($result->brand_position)->toBe(1)
        ->and($result->brand_cited)->toBeTrue()
        ->and($result->mentions()->where('subject_type', 'competitor')->value('position'))->toBe(2)
        ->and($result->citations()->value('is_brand'))->toBeTrue();

    // Running it again changes nothing and doesn't duplicate mentions.
    expect(app(Redetector::class)->brand($this->brand))->toBe(0)
        ->and($result->mentions()->count())->toBe(2);
});

it('keeps answer analysis for subjects still found, and re-analyses new ones', function () {
    $sony = $this->brand->competitors()->createQuietly(['name' => 'PlayStation']);
    $this->result->mentions()->create(['subject_type' => 'competitor', 'subject_id' => $sony->id, 'name_matched' => 'PlayStation', 'position' => 1, 'count' => 1, 'snippet' => 'x', 'sentiment' => 'positive', 'descriptors' => ['powerful']]);
    $this->result->forceFill(['analysis_status' => 'done'])->save();

    app(Redetector::class)->brand($this->brand);

    $competitor = $this->result->mentions()->where('subject_type', 'competitor')->first();

    expect($competitor->sentiment)->toBe('positive')
        ->and($competitor->descriptors)->toBe(['powerful'])
        // Nintendo is newly found, so the answer goes back for analysis.
        ->and($this->result->fresh()->analysis_status)->toBe('pending');
});

it('re-checks past answers when the brand or its competitors change', function () {
    Queue::fake();

    $this->brand->update(['aliases' => ['Switch']]);
    Queue::assertPushed(RedetectBrandJob::class, fn ($job) => $job->brandId === $this->brand->id);

    Queue::fake();
    $this->brand->update(['description' => 'Games']);
    Queue::assertNotPushed(RedetectBrandJob::class);

    $this->brand->competitors()->create(['name' => 'Xbox']);
    Queue::assertPushed(RedetectBrandJob::class);
});

it('can be run by hand', function () {
    $this->artisan('ai-visibility:redetect', ['--brand' => $this->brand->id])
        ->expectsOutputToContain('1 answers changed')
        ->assertSuccessful();
});
