<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\CompetitorLabel;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\EditBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\CandidateResource\Pages\ListCandidates;
use IsrarMinhas\FilamentAiVisibility\Models\Candidate;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);

    $this->globex = Candidate::query()->create([
        'brand_id' => $this->brand->id, 'key' => 'name:globex', 'kind' => 'name', 'name' => 'Globex', 'domain' => 'globex.io',
        'answers' => 5, 'prompts' => 3, 'engines' => ['openai'], 'score' => 72,
        'status' => Candidate::STATUS_CLASSIFIED, 'label' => CompetitorLabel::DirectCompetitor, 'confidence' => 'high',
    ]);
    $this->globex->classifications()->create(['label' => CompetitorLabel::DirectCompetitor, 'confidence' => 'high', 'company_name' => 'Globex Inc', 'reason' => 'Sells the same CRM', 'evidence' => ['mentions' => ['Globex is a CRM.']]]);

    $this->g2 = Candidate::query()->create([
        'brand_id' => $this->brand->id, 'key' => 'domain:g2.com', 'kind' => 'domain', 'name' => 'g2.com', 'domain' => 'g2.com',
        'answers' => 3, 'prompts' => 2, 'engines' => ['openai'], 'score' => 40, 'status' => Candidate::STATUS_NEW,
    ]);
});

it('lists open candidates by score with a badge for likely competitors', function () {
    livewire(ListCandidates::class)
        ->assertCanSeeTableRecords([$this->globex, $this->g2], inOrder: true)
        ->assertSee('Direct competitor');

    expect(CandidateResource::getNavigationBadge())->toBe('1');
    $this->get(CandidateResource::getUrl())->assertOk();
});

it('tracks a candidate as a competitor', function () {
    livewire(ListCandidates::class)
        ->callAction(TestAction::make('accept')->table($this->globex))
        ->assertNotified('Now tracking Globex Inc');

    expect($this->brand->competitors()->pluck('name')->all())->toBe(['Globex Inc'])
        ->and($this->globex->fresh()->status)->toBe(Candidate::STATUS_ACCEPTED);

    livewire(ListCandidates::class)->assertCanNotSeeTableRecords([$this->globex]);
});

it('shows the evidence and reason', function () {
    livewire(ListCandidates::class)
        ->mountAction(TestAction::make('view')->table($this->globex))
        ->assertMountedActionModalSee(['Sells the same CRM', 'Globex is a CRM.', 'High confidence']);
});

it('corrects a label', function () {
    livewire(ListCandidates::class)
        ->callAction(TestAction::make('relabel')->table($this->g2), ['label' => 'review_comparison'])
        ->assertNotified('Label updated');

    expect($this->g2->fresh()->label)->toBe(CompetitorLabel::ReviewComparison)
        ->and($this->g2->latestClassification->override_label)->toBe(CompetitorLabel::ReviewComparison);
});

it('rejects and ignores in bulk', function () {
    livewire(ListCandidates::class)
        ->selectTableRecords([$this->globex->id, $this->g2->id])
        ->callAction(TestAction::make('ignoreSelected')->table()->bulk());

    expect(Candidate::query()->pluck('status')->unique()->all())->toBe([Candidate::STATUS_IGNORED]);
});

it('discovers from the brand page', function () {
    app(KeyResolver::class)->remove('openai');

    livewire(EditBrand::class, ['record' => $this->brand->getRouteKey()])
        ->callAction('discover')
        ->assertNotified();
});

it('suggests competitors in the setup wizard', function () {
    app(Settings::class)->record()->forceFill(['setup_completed_at' => null, 'setup_step' => 5])->save();
    app(KeyResolver::class)->store('openai', 'sk');
    Http::fake(['api.openai.com/*' => Http::response([
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"competitors":[{"name":"Globex","domain":"globex.io"},{"name":"Hooli","domain":"hooli.com"}]}']]]],
        'usage' => [],
    ])]);

    $component = livewire(Setup::class)
        ->callAction(TestAction::make('suggestCompetitors')->schemaComponent('competitorActions', schema: 'form'))
        ->assertNotified('Added 2 suggestions');

    expect(collect($component->get('data.competitors'))->pluck('name')->values()->all())->toBe(['Globex', 'Hooli']);
})->skip(fn () => ! method_exists(TestAction::class, 'schemaComponent'), 'Needs schema component action testing');

it('saves discovery settings and custom instructions', function () {
    livewire(ManageSettings::class)
        ->fillForm([
            'discovery' => ['top_n' => 10, 'auto_accept' => true, 'ignored_domains' => ['https://www.Reddit.com/r/x']],
            'instructions' => [
                Instructions::CLASSIFICATION => 'My own rules for {brand}',
                Instructions::EXTRACTION => Instructions::default(Instructions::EXTRACTION),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(Settings::class);

    expect($settings->get('discovery.top_n'))->toBe(10)
        ->and($settings->get('discovery.auto_accept'))->toBeTrue()
        ->and($settings->get('discovery.ignored_domains'))->toBe(['reddit.com'])
        ->and(app(Instructions::class)->render(Instructions::CLASSIFICATION, ['brand' => 'Acme']))->toBe('My own rules for Acme')
        // Unchanged defaults are not stored, so future improvements to the default apply.
        ->and($settings->get('instructions.extraction'))->toBe('');
});
