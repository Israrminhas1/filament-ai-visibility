<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Support\Health\SystemHealth;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = $this->createUser();
    $this->actingAs($this->user);
});

it('sends every AI Visibility screen to setup until it is complete', function () {
    $this->get(BrandResource::getUrl())->assertRedirect(Setup::getUrl());
    $this->get(Health::getUrl())->assertOk();
    $this->get(Setup::getUrl())->assertOk()->assertSee('Set up AI Visibility');

    $this->completeSetup();

    $this->get(BrandResource::getUrl())->assertOk();
});

it('lives under the plugin prefix on a panel with any ID', function () {
    expect(Setup::getUrl())->toEndWith('/console/ai-visibility/setup')
        ->and(BrandResource::getUrl())->toEndWith('/console/ai-visibility/brands');
});

it('blocks the first step on failing system checks unless the user continues anyway', function () {
    // A halted step never tells the browser to move on.
    livewire(Setup::class)
        ->goToWizardStep(2)
        ->assertNotified('Some system checks failed')
        ->assertNotDispatched('next-wizard-step');

    expect(app(Settings::class)->setupStep())->toBe(1);

    livewire(Setup::class)
        ->fillForm(['continue_with_warnings' => true])
        ->goToWizardStep(2)
        ->assertDispatched('next-wizard-step');

    expect(app(Settings::class)->setupStep())->toBe(2);
});

it('passes the system step when the queue and scheduler are alive', function () {
    Heartbeat::beat(SystemHealth::SCHEDULER);
    Heartbeat::beat(SystemHealth::queueHeartbeatName());

    livewire(Setup::class)
        ->goToWizardStep(2)
        ->assertWizardCurrentStep(2);
});

it('runs the whole wizard with a single OpenAI key', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-5-mini']]]),
        'acme.com' => Http::response('<html><head><title>Acme CRM | CRM for agencies</title><meta name="description" content="The CRM agencies love."></head></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $page = livewire(Setup::class)
        ->fillForm(['continue_with_warnings' => true])
        ->goToWizardStep(2)
        // Engines: turning on an engine without a key fails.
        ->fillForm(['engines' => ['anthropic' => ['enabled' => true]]])
        ->goToWizardStep(3)
        ->assertNotified('Some keys did not work')
        ->fillForm(['engines' => [
            'anthropic' => ['enabled' => false],
            'openai' => ['enabled' => true, 'api_key' => 'sk-live-123'],
        ]])
        ->goToWizardStep(3)
        ->assertWizardCurrentStep(3)
        ->assertSchemaStateSet(['engines.openai.api_key' => null])
        // Budget.
        ->fillForm(['runs' => ['frequency' => 'daily', 'samples' => 2], 'budget' => ['monthly_usd' => 50]])
        ->assertSee('per month')
        ->goToWizardStep(4)
        // Brand, prefilled from the website.
        ->fillForm(['brand' => ['domain' => 'https://www.acme.com/']])
        ->callAction(\Filament\Actions\Testing\TestAction::make('fetchWebsite')->schemaComponent('brand.domain'))
        ->assertSchemaStateSet(['brand.name' => 'Acme CRM', 'brand.description' => 'The CRM agencies love.', 'brand.domain' => 'acme.com'])
        ->goToWizardStep(5)
        // Competitors.
        ->fillForm(['competitors' => [['name' => 'HubSpot', 'domain' => 'hubspot.com'], ['name' => 'Pipedrive', 'domain' => '']]])
        ->goToWizardStep(6)
        // Keywords.
        ->fillForm(['keywords_text' => "crm for agencies\nbest crm"])
        ->goToWizardStep(7)
        // Prompts: at least one is required.
        ->goToWizardStep(8)
        ->assertNotified('Add at least one prompt')
        ->fillForm(['prompts_text' => "What is the best CRM for agencies?\nWhich CRM works with Slack?"])
        ->goToWizardStep(8)
        // Alerts.
        ->fillForm(['alerts' => ['emails' => ['ops@example.com'], 'slack_webhook' => null]])
        ->goToWizardStep(9)
        ->assertSee('Acme CRM')
        ->call('finish')
        ->assertHasNoFormErrors();

    $brand = Brand::query()->first();
    $settings = app(Settings::class);

    expect($settings->isSetupComplete())->toBeTrue()
        ->and($settings->get('engines.enabled'))->toBe(['openai'])
        ->and($settings->get('runs.samples'))->toBe(2)
        ->and($settings->get('budget.monthly_usd'))->toEqual(50)
        ->and($settings->get('alerts.emails'))->toBe(['ops@example.com'])
        ->and(app(KeyResolver::class)->resolve('openai'))->toBe('sk-live-123')
        ->and($brand->name)->toBe('Acme CRM')
        ->and($brand->domains)->toBe(['acme.com'])
        ->and($brand->competitors()->pluck('name')->all())->toBe(['HubSpot', 'Pipedrive'])
        ->and($brand->keywords()->count())->toBe(2)
        ->and($brand->activePrompts()->count())->toBe(2);

    $page->assertRedirect(BrandResource::getUrl('edit', ['record' => $brand]));
});

it('resumes where the user left off', function () {
    app(Settings::class)->setSetupStep(4);

    livewire(Setup::class)->assertWizardCurrentStep(4);
});
