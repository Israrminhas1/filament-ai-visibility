<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\CreateBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\EditBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers\CompetitorsRelationManager;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers\KeywordsRelationManager;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\RelationManagers\PromptsRelationManager;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource\Pages\ManagePrompts;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();
});

describe('settings page', function () {
    it('saves settings and keys without sending keys back to the browser', function () {
        livewire(ManageSettings::class)
            ->fillForm([
                'engines' => ['gemini' => ['enabled' => true, 'api_key' => 'g-secret-key', 'model' => 'gemini-2.5-pro']],
                'runs' => ['samples' => 3],
                'limits' => ['max_active_prompts_per_brand' => null, 'max_competitors_per_brand' => 5],
                'budget' => ['monthly_usd' => 25],
                'kill_switch' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved')
            ->assertSchemaStateSet(['engines.gemini.api_key' => null, 'engines.gemini.enabled' => true])
            ->assertDontSee('g-secret-key');

        $settings = app(Settings::class);

        expect($settings->get('engines.enabled'))->toBe(['gemini'])
            ->and($settings->get('engines.models'))->toBe(['gemini' => 'gemini-2.5-pro'])
            ->and($settings->get('runs.samples'))->toBe(3)
            ->and($settings->get('limits.max_active_prompts_per_brand'))->toBe(0)
            ->and($settings->get('limits.max_competitors_per_brand'))->toBe(5)
            ->and($settings->killSwitch())->toBeTrue()
            ->and(app(KeyResolver::class)->resolve('gemini'))->toBe('g-secret-key');
    });

    it('keeps an existing key when the field is left empty', function () {
        app(KeyResolver::class)->store('openai', 'sk-keep-me');

        livewire(ManageSettings::class)
            ->fillForm(['engines' => ['openai' => ['enabled' => true]]])
            ->call('save');

        expect(app(KeyResolver::class)->resolve('openai'))->toBe('sk-keep-me');
    });
});

describe('health page', function () {
    it('shows paused engines with their fix, and resumes after a passing test', function () {
        Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);
        app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
        app(KeyResolver::class)->store('openai', 'sk');
        app(EngineManager::class)->pause('openai', PauseReason::InsufficientCredits);

        expect(Health::getNavigationBadge())->toBe('1');

        livewire(Health::class)
            ->assertSee('Out of credits')
            ->assertSee(PauseReason::InsufficientCredits->fix())
            ->call('testAndResume', 'openai')
            ->assertNotified('Engine resumed');

        expect(app(EngineManager::class)->isUsable('openai'))->toBeTrue()
            ->and(Health::getNavigationBadge())->toBeNull();
    });

    it('renders', function () {
        $this->get(Health::getUrl())->assertOk()->assertSee('Queue worker')->assertSee('Scheduler');
    });
});

describe('brands', function () {
    it('creates a brand and keeps only filled overrides', function () {
        livewire(CreateBrand::class)
            ->fillForm([
                'name' => 'Acme',
                'domains' => ['https://www.acme.com'],
                'run_frequency' => 'daily',
                'settings' => [
                    'runs' => ['samples' => 3],
                    'limits' => ['max_active_prompts_per_brand' => null],
                    'engines' => ['enabled' => []],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = Brand::query()->first();

        expect($brand->domains)->toBe(['acme.com'])
            ->and($brand->settings)->toBe(['runs' => ['samples' => 3]])
            ->and($brand->setting('runs.samples'))->toBe(3);
    });

    it('shows a limit notification instead of an error', function () {
        app(Settings::class)->set(['limits' => ['max_brands' => 1]]);
        $this->createBrand();

        livewire(CreateBrand::class)
            ->fillForm(['name' => 'Second', 'domains' => ['second.com'], 'run_frequency' => 'weekly'])
            ->call('create')
            ->assertNotified('Limit reached');

        expect(Brand::query()->count())->toBe(1);
    });

    it('renders the list and edit pages', function () {
        $brand = $this->createBrand();

        $this->get(BrandResource::getUrl())->assertOk()->assertSee('Acme');
        $this->get(BrandResource::getUrl('edit', ['record' => $brand]))->assertOk();
    });

    it('manages competitors within the limit', function () {
        app(Settings::class)->set(['limits' => ['max_competitors_per_brand' => 1]]);
        $brand = $this->createBrand();

        livewire(CompetitorsRelationManager::class, ['ownerRecord' => $brand, 'pageClass' => EditBrand::class])
            ->callAction(TestAction::make('create')->table(), ['name' => 'HubSpot', 'domains' => ['hubspot.com']])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Pipedrive'])
            ->assertNotified('Limit reached');

        expect($brand->competitors()->pluck('name')->all())->toBe(['HubSpot']);
    });
});

describe('prompts', function () {
    it('adds prompts in bulk on a brand, pausing those over the limit', function () {
        app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 2]]);
        $brand = $this->createBrand();

        livewire(PromptsRelationManager::class, ['ownerRecord' => $brand, 'pageClass' => EditBrand::class])
            ->callAction(TestAction::make('addPrompts')->table(), [
                'lines' => "Best CRM?\nCheapest CRM?\nCRM with Slack?\nBest CRM?",
                'activate' => true,
            ])
            ->assertNotified('Added 3 prompts');

        expect($brand->activePrompts()->count())->toBe(2)
            ->and($brand->prompts()->where('status', PromptStatus::Paused)->count())->toBe(1);
    });

    it('rejects duplicates and activation over the limit in the form', function () {
        app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 1]]);
        $brand = $this->createBrand();
        $brand->prompts()->create(['text' => 'Best CRM?']);

        livewire(ManagePrompts::class)
            ->callAction('create', ['brand_id' => $brand->id, 'text' => 'best crm', 'intent' => 'discovery', 'status' => 'paused'])
            ->assertHasFormErrors(['text']);

        livewire(ManagePrompts::class)
            ->callAction('create', ['brand_id' => $brand->id, 'text' => 'Another prompt', 'intent' => 'discovery', 'status' => 'active'])
            ->assertHasFormErrors(['status']);

        livewire(ManagePrompts::class)
            ->callAction('create', ['brand_id' => $brand->id, 'text' => 'Another prompt', 'intent' => 'discovery', 'status' => 'paused'])
            ->assertHasNoFormErrors();

        expect($brand->prompts()->count())->toBe(2);
    });

    it('bulk-activates only up to the limit', function () {
        app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 2]]);
        $brand = $this->createBrand();
        $prompts = collect(['a', 'b', 'c'])->map(fn ($text) => $brand->prompts()->create(['text' => $text, 'status' => PromptStatus::Paused]));

        livewire(ManagePrompts::class)
            ->selectTableRecords($prompts->pluck('id')->all())
            ->callAction(TestAction::make('activate')->table()->bulk())
            ->assertNotified('Activated 2 prompts');

        expect($brand->activePrompts()->count())->toBe(2);
    });

    it('lists prompts across brands', function () {
        $brand = $this->createBrand();
        $prompt = $brand->prompts()->create(['text' => 'Best CRM for agencies?']);

        livewire(ManagePrompts::class)->assertCanSeeTableRecords([$prompt]);
        $this->get(PromptResource::getUrl())->assertOk();
    });
});

describe('keywords', function () {
    it('imports keywords from a CSV upload', function () {
        $brand = $this->createBrand();
        $csv = \Illuminate\Http\UploadedFile::fake()->createWithContent('keywords.csv', "keyword,search_volume\ncrm for agencies,1200\nbest crm,300\n");

        livewire(KeywordsRelationManager::class, ['ownerRecord' => $brand, 'pageClass' => EditBrand::class])
            ->callAction(TestAction::make('addKeywords')->table(), ['csv' => $csv])
            ->assertNotified('Added 2 keywords');

        expect($brand->keywords()->orderByDesc('search_volume')->pluck('search_volume', 'keyword')->all())
            ->toBe(['crm for agencies' => 1200, 'best crm' => 300]);

        $this->get(KeywordResource::getUrl())->assertOk()->assertSee('crm for agencies');
    });
});
