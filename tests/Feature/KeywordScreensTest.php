<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\TopicsReport;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\EditBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource\Pages\ManageConnections;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource\Pages\ListKeywords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages\ManagePrompts;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\TopicPerformance;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

function aiReplies(array ...$replies)
{
    $sequence = Http::sequence();

    foreach ($replies as $json) {
        $sequence->push(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]], 'usage' => []]);
    }

    return $sequence;
}

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    app(KeyResolver::class)->store('openai', 'sk');
});

describe('keyword sources screen', function () {
    it('connects a source, testing it straight away', function () {
        Http::fake(['serpapi.com/account.json*' => Http::response(['total_searches_left' => 95])]);

        livewire(ManageConnections::class)
            ->callAction('create', [
                'brand_id' => $this->brand->id,
                'type' => 'serpapi',
                'name' => 'People also ask',
                'credentials' => ['api_key' => 'serp-key'],
                'config' => ['max_seeds' => 5],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Connected');

        $connection = Connection::query()->first();

        expect($connection->credential('api_key'))->toBe('serp-key')
            ->and($connection->setting('max_seeds'))->toBe(5);

        $this->get(ConnectionResource::getUrl())->assertOk()->assertSee('People also ask')->assertDontSee('serp-key');
    });

    it('keeps saved secrets when they are left empty on edit', function () {
        $connection = Connection::query()->create(['brand_id' => $this->brand->id, 'type' => 'serpapi', 'name' => 'PAA', 'credentials' => ['api_key' => 'keep-me']]);

        livewire(ManageConnections::class)
            ->mountAction(TestAction::make('edit')->table($connection))
            ->assertSchemaStateSet(['credentials.api_key' => null])
            ->fillForm(['name' => 'Renamed'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect($connection->fresh())
            ->name->toBe('Renamed')
            ->and($connection->fresh()->credential('api_key'))->toBe('keep-me');
    });

    it('syncs from the table', function () {
        $this->brand->keywords()->create(['keyword' => 'crm']);
        $connection = Connection::query()->create(['brand_id' => $this->brand->id, 'type' => 'serpapi', 'name' => 'PAA', 'credentials' => ['api_key' => 'k']]);
        Http::fake(['serpapi.com/search.json*' => Http::response(['related_questions' => [['question' => 'Which CRM is best?']]])]);

        livewire(ManageConnections::class)
            ->callAction(TestAction::make('sync')->table($connection))
            ->assertNotified('1 new keywords, 0 updated');
    });
});

describe('generation screens', function () {
    it('generates prompts from the prompts page', function () {
        Http::fake(['api.openai.com/*' => aiReplies(
            ['prompts' => [['text' => 'Which CRM suits a small design agency?', 'intent' => 'discovery']]],
            ['reviews' => [['id' => 0, 'score' => 5, 'reason' => 'Realistic']]],
        )]);

        livewire(ManagePrompts::class)
            ->callAction('generatePrompts', ['brand_id' => $this->brand->id, 'count' => 5, 'intents' => ['discovery'], 'use_keywords' => true])
            ->assertNotified('1 prompts suggested for Acme');

        expect(Prompt::query()->where('status', PromptStatus::Suggested)->count())->toBe(1);
    });

    it('generates prompts from selected keywords', function () {
        $keyword = $this->brand->keywords()->create(['keyword' => 'agency crm']);
        app(Settings::class)->set(['generation' => ['ai_review' => false]]);
        Http::fake(['api.openai.com/*' => aiReplies(['prompts' => [['text' => 'What CRM do agencies use for client work?', 'intent' => 'discovery', 'keyword' => 'agency crm']]])]);

        livewire(ListKeywords::class)
            ->selectTableRecords([$keyword->id])
            ->callAction(TestAction::make('generateFromKeywords')->table()->bulk(), ['count' => 3, 'intents' => ['discovery']]);

        expect(Prompt::query()->first()->keywords->pluck('id')->all())->toBe([$keyword->id]);
    });

    it('organises prompts into topics after review', function () {
        $prompt = $this->brand->prompts()->create(['text' => 'Cheapest CRM for agencies?']);
        Http::fake(['api.openai.com/*' => aiReplies(['topics' => [['name' => 'Pricing', 'prompt_ids' => [$prompt->id]]]])]);

        livewire(EditBrand::class, ['record' => $this->brand->getRouteKey()])
            ->mountAction('organiseTopics')
            ->assertSchemaStateSet(function (array $state) {
                // The AI proposal is shown in the form for review before anything changes.
                expect(collect($state['topics'])->pluck('name')->all())->toBe(['Pricing']);

                return [];
            })
            ->callMountedAction()
            ->assertNotified('1 prompts assigned to 1 topics');

        expect($prompt->fresh()->topic->name)->toBe('Pricing');
    })->skip(fn () => ! method_exists(\Filament\Actions\Testing\TestAction::class, 'table'), 'Needs Filament 4+');

    it('fills the setup wizard with generated prompts', function () {
        app(Settings::class)->record()->forceFill(['setup_completed_at' => null, 'setup_step' => 7])->save();
        app(Settings::class)->set(['generation' => ['ai_review' => false]]);
        Http::fake(['api.openai.com/*' => aiReplies(['prompts' => [
            ['text' => 'Which CRM integrates best with Slack?', 'intent' => 'discovery'],
            ['text' => 'Is Acme any good?', 'intent' => 'discovery'],
        ]])]);

        $component = livewire(Setup::class)
            ->callAction(TestAction::make('generatePrompts')->schemaComponent('promptActions', schema: 'form'))
            ->assertNotified('Added 1 questions');

        expect($component->get('data.prompts_text'))->toBe('Which CRM integrates best with Slack?')
            ->and(Prompt::query()->count())->toBe(0);
    });
});

it('shows the topics report', function () {
    $this->brand->topics()->create(['name' => 'Pricing']);

    $this->get(TopicsReport::getUrl())->assertOk()->assertSee('Topics');
    livewire(TopicPerformance::class, ['pageFilters' => ['brand' => $this->brand->id]])->assertSee('Pricing');
});

it('saves generation settings', function () {
    livewire(ManageSettings::class)
        ->fillForm(['generation' => ['count' => 20, 'min_quality' => 3, 'ai_review' => false, 'intents' => ['comparison']], 'keywords' => ['sync_days' => 7]])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(Settings::class);

    expect($settings->get('generation.count'))->toBe(20)
        ->and($settings->get('generation.intents'))->toBe(['comparison'])
        ->and($settings->get('generation.ai_review'))->toBeFalse()
        ->and($settings->get('keywords.sync_days'))->toBe(7);
});
