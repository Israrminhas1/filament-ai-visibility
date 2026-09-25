<?php

use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\CompetitorsReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\HeadToHeadReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\OpportunitiesReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();

    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->globex = $this->brand->competitors()->create(['name' => 'Globex']);
    $prompt = $this->brand->prompts()->create(['text' => 'Cheap CRM?']);
    $run = Run::query()->create(['brand_id' => $this->brand->id, 'results_total' => 2]);

    $this->answer = Result::query()->create([
        'run_id' => $run->id, 'brand_id' => $this->brand->id, 'prompt_id' => $prompt->id, 'engine' => 'openai',
        'status' => ResultStatus::Success, 'answer' => 'Acme is affordable. Globex too.', 'ran_at' => now(),
        'brand_mentioned' => true, 'brand_position' => 1, 'analysis_status' => 'done',
    ]);
    $this->answer->mentions()->create(['subject_type' => 'brand', 'subject_id' => $this->brand->id, 'name_matched' => 'Acme', 'position' => 1, 'sentiment' => 'positive', 'recommendation' => 'top_pick', 'descriptors' => ['affordable']]);
    $this->answer->mentions()->create(['subject_type' => 'competitor', 'subject_id' => $this->globex->id, 'name_matched' => 'Globex', 'position' => 2]);

    $gap = Result::query()->create([
        'run_id' => $run->id, 'brand_id' => $this->brand->id, 'prompt_id' => $prompt->id, 'engine' => 'gemini',
        'status' => ResultStatus::Success, 'answer' => 'Globex.', 'ran_at' => now(),
    ]);
    $gap->mentions()->create(['subject_type' => 'competitor', 'subject_id' => $this->globex->id, 'name_matched' => 'Globex', 'position' => 1]);
    $gap->citations()->create(['url' => 'https://g2.com/x', 'domain' => 'g2.com', 'category' => 'review_comparison', 'position' => 1]);
});

it('renders the competitor reports', function () {
    expect(CompetitorsReport::getUrl())->toEndWith('/console/ai-visibility/competitors');

    $this->get(CompetitorsReport::getUrl())->assertOk()->assertSee('Competitors');
    $this->get(HeadToHeadReport::getUrl())->assertOk()->assertSee('Head-to-head');
    $this->get(OpportunitiesReport::getUrl())->assertOk()->assertSee('Opportunities');
});

it('renders every competitor widget', function (string $widget) {
    livewire($widget, ['pageFilters' => ['brand' => $this->brand->id, 'period' => 30]])->assertOk();
    livewire($widget, ['pageFilters' => null])->assertOk();
})->with([
    Widgets\CompetitorLeaderboard::class,
    Widgets\EngineHeatmap::class,
    Widgets\BrandPerception::class,
    Widgets\HeadToHead::class,
    Widgets\Opportunities::class,
]);

it('shows the numbers', function () {
    livewire(Widgets\CompetitorLeaderboard::class, ['pageFilters' => ['brand' => $this->brand->id]])
        ->assertSeeInOrder(['Globex', '100%', 'Acme', '50%']);

    livewire(Widgets\BrandPerception::class, ['pageFilters' => ['brand' => $this->brand->id]])
        ->assertSee('Positive 100%')
        ->assertSee('affordable · 1');

    livewire(Widgets\HeadToHead::class, ['pageFilters' => ['brand' => $this->brand->id, 'competitor' => $this->globex->id]])
        ->assertSee('50% named together');

    livewire(Widgets\Opportunities::class, ['pageFilters' => ['brand' => $this->brand->id]])
        ->assertSee('Cheap CRM?')
        ->assertSee('g2.com');
});

it('shows the analysis on an answer', function () {
    $this->get(ResultResource::getUrl('view', ['record' => $this->answer]))
        ->assertOk()
        ->assertSee('Top pick')
        ->assertSee('affordable');
});
