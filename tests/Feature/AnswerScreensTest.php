<?php

use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages\ListResults;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\Pages\ViewRun;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource\RelationManagers\ResultsRelationManager;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();

    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->globex = $this->brand->competitors()->create(['name' => 'Globex', 'domains' => ['globex.com']]);
    $this->initech = $this->brand->competitors()->create(['name' => 'Initech']);
    $this->prompt = $this->brand->prompts()->create(['text' => 'Best CRM for agencies?']);
    $this->run = Run::create(['brand_id' => $this->brand->id, 'status' => 'completed']);

    $this->answer = Result::create([
        'run_id' => $this->run->id,
        'brand_id' => $this->brand->id,
        'prompt_id' => $this->prompt->id,
        'engine' => 'openai',
        'model' => 'gpt-5',
        'status' => ResultStatus::Success,
        'answer' => "## Best CRMs for agencies\n\n1. **Globex** is popular.\n2. **Acme** is great for agencies, see [the guide](https://acme.com/guide).\n\n- Initech is cheaper\n- Easy setup",
        'brand_mentioned' => true,
        'brand_position' => 2,
        'brand_mention_count' => 1,
        'brand_cited' => true,
        'brand_sentiment' => 'positive',
        'ran_at' => now()->subDay(),
    ]);

    $this->answer->mentions()->createMany([
        ['subject_type' => 'competitor', 'subject_id' => $this->globex->id, 'name_matched' => 'Globex', 'position' => 1, 'snippet' => '1. **Globex** is popular.'],
        ['subject_type' => 'brand', 'subject_id' => $this->brand->id, 'name_matched' => 'Acme', 'position' => 2, 'snippet' => '## **Acme** is great', 'sentiment' => 'positive', 'descriptors' => ['easy to use']],
        ['subject_type' => 'competitor', 'subject_id' => $this->initech->id, 'name_matched' => 'Initech', 'position' => 3],
        ['subject_type' => 'entity', 'subject_id' => null, 'name_matched' => 'Umbrella Corp', 'position' => 4],
    ]);

    $this->answer->citations()->createMany([
        ['url' => 'https://acme.com/guide', 'domain' => 'acme.com', 'title' => 'The **Acme** guide', 'position' => 1, 'is_brand' => true],
        ['url' => 'https://reviews.example/crm', 'domain' => 'reviews.example', 'title' => 'CRM reviews', 'position' => 2],
    ]);

    $this->missed = Result::create([
        'run_id' => $this->run->id,
        'brand_id' => $this->brand->id,
        'prompt_id' => $this->prompt->id,
        'engine' => 'gemini',
        'status' => ResultStatus::Success,
        'answer' => 'Nothing about you.',
        'ran_at' => now()->subDays(10),
    ]);

    $this->skipped = Result::create([
        'run_id' => $this->run->id,
        'brand_id' => $this->brand->id,
        'prompt_id' => $this->prompt->id,
        'engine' => 'grok',
        'status' => ResultStatus::Skipped,
        'skip_reason' => 'No API key',
    ]);
});

it('shows one verdict, competitors and sources per answer', function () {
    livewire(ListResults::class)
        ->assertCanSeeTableRecords([$this->answer, $this->missed, $this->skipped])
        ->assertTableColumnStateSet('result', '#2 · cited', $this->answer)
        ->assertTableColumnStateSet('result', 'Not mentioned', $this->missed)
        ->assertTableColumnStateSet('result', 'Skipped', $this->skipped)
        ->assertTableColumnStateSet('competitors', ['Globex', 'Initech'], $this->answer)
        ->assertTableColumnStateSet('citations_count', 2, $this->answer)
        ->assertSee('Acme');
});

it('filters answers by competitor, date and sentiment', function () {
    livewire(ListResults::class)
        ->filterTable('competitor', $this->initech->id)
        ->assertCanSeeTableRecords([$this->answer])
        ->assertCanNotSeeTableRecords([$this->missed, $this->skipped]);

    livewire(ListResults::class)
        ->filterTable('answered', ['from' => now()->subDays(3)->toDateString()])
        ->assertCanSeeTableRecords([$this->answer])
        ->assertCanNotSeeTableRecords([$this->missed]);

    livewire(ListResults::class)
        ->assertTableFilterVisible('brand_sentiment')
        ->filterTable('brand_sentiment', 'positive')
        ->assertCanSeeTableRecords([$this->answer])
        ->assertCanNotSeeTableRecords([$this->missed]);
});

it('limits the competitors column to three names', function () {
    foreach (['Hooli', 'Vandelay'] as $index => $name) {
        $competitor = $this->brand->competitors()->create(['name' => $name]);
        $this->answer->mentions()->create(['subject_type' => 'competitor', 'subject_id' => $competitor->id, 'name_matched' => $name, 'position' => 5 + $index]);
    }

    livewire(ListResults::class)
        ->assertTableColumnStateSet('competitors', ['Globex', 'Initech', 'Hooli', '+1'], $this->answer);
});

it('does not repeat the brand inside a run', function () {
    livewire(ResultsRelationManager::class, ['ownerRecord' => $this->run, 'pageClass' => ViewRun::class])
        ->assertCanSeeTableRecords([$this->answer])
        ->assertTableFilterHidden('brand');
});

it('renders the answer as markdown with plain-text snippets', function () {
    $this->get(ResultResource::getUrl('view', ['record' => $this->answer]))
        ->assertOk()
        // Title and meta line.
        ->assertSee('Best CRM for agencies?')
        ->assertSee('Acme · ' . ResultResource::engineLabel('openai') . ' · gpt-5 · sample 1')
        // Markdown is rendered, not shown raw.
        ->assertSee('<h2>', escape: false)
        ->assertSee('<ol>', escape: false)
        ->assertSee('<ul>', escape: false)
        ->assertSee('<strong>', escape: false)
        ->assertDontSee('## Best CRMs')
        ->assertDontSee('**Globex**')
        // Mentions: brand first, then competitors, then other names; snippets without markdown.
        ->assertSeeInOrder(['Who was mentioned', 'Acme', 'You', 'Globex', 'Competitor', 'Initech', 'Umbrella Corp', 'Other'])
        ->assertSee('Acme is great')
        ->assertSee('easy to use')
        // Sources split by whether the answer cites them.
        ->assertSeeInOrder(['Cited in the answer', 'acme.com', 'Your site', 'Also read', 'reviews.example'])
        ->assertSee('The Acme guide');
});

it('links to the neighbouring answers of the run', function () {
    $this->get(ResultResource::getUrl('view', ['record' => $this->missed]))
        ->assertOk()
        ->assertSee(ResultResource::getUrl('view', ['record' => $this->answer]), escape: false)
        ->assertSee(ResultResource::getUrl('view', ['record' => $this->skipped]), escape: false);

    $this->get(ResultResource::getUrl('view', ['record' => $this->skipped]))
        ->assertOk()
        ->assertSee('Skipped: No API key');
});

it('strips markdown from snippets', function () {
    expect(ResultResource::plainText("## **Acme** is [great](https://acme.com)\n- and `fast`"))->toBe('Acme is great and fast');
});
