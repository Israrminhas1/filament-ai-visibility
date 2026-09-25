<?php

use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Overview;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\SourcesReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages\ManagePrompts;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages\ListResults;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();
});

function seedAnswers($test): void
{
    $test->brand = $test->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $globex = $test->brand->competitors()->create(['name' => 'Globex']);
    $test->prompt = $test->brand->prompts()->create(['text' => 'Best CRM for agencies?']);
    $run = Run::query()->create(['brand_id' => $test->brand->id, 'results_total' => 2]);

    foreach ([[true, now()->subDays(2)], [false, now()]] as [$mentioned, $ranAt]) {
        $result = Result::query()->create([
            'run_id' => $run->id, 'brand_id' => $test->brand->id, 'prompt_id' => $test->prompt->id,
            'engine' => 'openai', 'model' => 'gpt-5-mini', 'status' => ResultStatus::Success,
            'brand_mentioned' => $mentioned, 'brand_position' => $mentioned ? 1 : null,
            'answer' => $mentioned ? 'Acme is best, then Globex.' : '=cmd| Globex only.', 'ran_at' => $ranAt,
        ]);

        $result->mentions()->create(['subject_type' => 'competitor', 'subject_id' => $globex->id, 'name_matched' => 'Globex', 'position' => $mentioned ? 2 : 1]);
        $result->citations()->create(['url' => 'https://g2.com/crm', 'domain' => 'g2.com', 'category' => 'review_comparison', 'position' => 1]);
    }
}

it('shows an empty state before there are brands', function () {
    $this->get(Overview::getUrl())->assertOk()->assertSee('No data yet');
});

it('renders the overview and sources report', function () {
    seedAnswers($this);

    expect(Overview::getUrl())->toEndWith('/console/ai-visibility');

    $this->get(Overview::getUrl())->assertOk()->assertSee('AI visibility');
    $this->get(SourcesReport::getUrl())->assertOk()->assertSee('Sources');
});

it('renders every report widget with filters', function (string $widget) {
    seedAnswers($this);

    livewire($widget, ['pageFilters' => ['brand' => $this->brand->id, 'period' => 7, 'engine' => 'openai']])->assertOk();
    livewire($widget, ['pageFilters' => null])->assertOk();
})->with([
    Widgets\VisibilityStats::class,
    Widgets\VisibilityTrendChart::class,
    Widgets\ShareOfVoiceChart::class,
    Widgets\EngineVisibilityChart::class,
    Widgets\TopSources::class,
    Widgets\PromptMovers::class,
    Widgets\SourceCategoriesChart::class,
    Widgets\OwnPagesCited::class,
]);

it('shows the visibility numbers', function () {
    seedAnswers($this);

    livewire(Widgets\VisibilityStats::class, ['pageFilters' => ['brand' => $this->brand->id, 'period' => 30]])
        ->assertSee('50%')
        ->assertSee('Share of voice');

    livewire(Widgets\TopSources::class, ['pageFilters' => ['brand' => $this->brand->id]])
        ->assertSee('g2.com')
        ->assertSee('Review &amp; comparison', escape: false);
});

it('warns on the overview when an engine is paused', function () {
    seedAnswers($this);
    app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
    app(\IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver::class)->store('openai', 'sk');
    app(EngineManager::class)->pause('openai', PauseReason::InsufficientCredits);

    expect(Widgets\PausedEngines::canView())->toBeTrue();
    livewire(Widgets\PausedEngines::class)->assertSee('Out of credits');

    app(EngineManager::class)->resume('openai');

    expect(Widgets\PausedEngines::canView())->toBeFalse();
});

it('shows a prompt history page', function () {
    seedAnswers($this);

    $this->get(PromptResource::getUrl('view', ['record' => $this->prompt]))
        ->assertOk()
        ->assertSee('Mentioned in 1 of the last 2 answers')
        ->assertSee('Brand no longer mentioned');
});

it('shows 30-day visibility in the prompts table', function () {
    seedAnswers($this);

    livewire(ManagePrompts::class)
        ->assertCanSeeTableRecords([$this->prompt])
        ->assertSee('50%')
        ->assertSee('1 of 2 answers');
});

it('exports answers and prompts as CSV', function () {
    seedAnswers($this);

    livewire(ListResults::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('ai-visibility-answers-' . now()->format('Y-m-d') . '.csv');

    livewire(ManagePrompts::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('ai-visibility-prompts-' . now()->format('Y-m-d') . '.csv');
});
