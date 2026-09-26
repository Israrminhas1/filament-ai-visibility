<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Overview;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\PausedEngines;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = $this->createUser(['name' => 'Ada Admin', 'email' => 'ada@acme.test']);
    $this->actingAs($this->user);
    $this->completeSetup();
});

describe('API keys', function () {
    it('labels the engines tab and links to it', function () {
        expect(ManageSettings::enginesUrl())->toContain('tab=engines');

        $this->get(ManageSettings::enginesUrl())
            ->assertOk()
            ->assertSee('Engines &amp; API keys', escape: false)
            ->assertSee('Run setup again');
    });

    it('removes a saved key', function () {
        app(KeyResolver::class)->store('openai', 'sk-remove-me');

        livewire(ManageSettings::class)
            ->callAction(TestAction::make('removeKey_openai')->schemaComponent('engineActions_openai', 'form'))
            ->assertNotified(app(\IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry::class)->get('openai')->label() . ' key removed');

        expect(app(KeyResolver::class)->has('openai'))->toBeFalse();
    });

    it('shows one shared SerpAPI key field for both Google engines', function () {
        livewire(ManageSettings::class)
            ->assertFormFieldExists('engines.google_ai_overview.api_key')
            ->assertFormFieldDoesNotExist('engines.google_ai_mode.api_key')
            ->assertSee('Uses the SerpAPI key entered under')
            ->fillForm(['engines' => ['google_ai_overview' => ['enabled' => true, 'api_key' => 'serp-123'], 'google_ai_mode' => ['enabled' => true]]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['engines.google_ai_overview.api_key' => null]);

        expect(app(KeyResolver::class)->resolve('google_ai_overview'))->toBe('serp-123')
            ->and(app(KeyResolver::class)->resolve('google_ai_mode'))->toBe('serp-123');
    });

    it('links key problems to Settings and other problems to Health', function () {
        app(Settings::class)->set(['engines' => ['enabled' => ['openai', 'gemini']]]);
        app(KeyResolver::class)->store('openai', 'sk');
        app(KeyResolver::class)->store('gemini', 'g');
        app(EngineManager::class)->pause('openai', PauseReason::InvalidKey);
        app(EngineManager::class)->pause('gemini', PauseReason::ProviderOutage);

        $issues = collect(PausedEngines::issues());

        expect($issues->first(fn ($issue) => str_contains($issue['text'], 'Invalid API key'))['url'])->toBe(ManageSettings::enginesUrl())
            ->and($issues->first(fn ($issue) => str_contains($issue['text'], 'Provider outage'))['url'])->toBe(Health::getUrl());

        $this->get(Health::getUrl())->assertOk()->assertSee('Change key / settings');
    });
});

describe('setup wizard', function () {
    it('drops legal suffixes from the fetched brand name and keeps the legal name as an alias', function () {
        Http::fake(['nintendo.com' => Http::response('<html><head><meta property="og:site_name" content="Nintendo Co., Ltd."></head></html>', 200, ['Content-Type' => 'text/html'])]);
        app(Settings::class)->setSetupStep(4);

        livewire(Setup::class)
            ->fillForm(['brand' => ['domain' => 'nintendo.com']])
            ->callAction(TestAction::make('fetchWebsite')->schemaComponent('brand.domain'))
            ->assertSchemaStateSet(['brand.name' => 'Nintendo', 'brand.aliases' => ['Nintendo Co., Ltd.']]);
    });
});

describe('permissions', function () {
    it('allows everything by default', function () {
        expect(ManageSettings::canAccess())->toBeTrue()
            ->and(Setup::canAccess())->toBeTrue()
            ->and(BrandResource::canAccess())->toBeTrue();
    });

    it('limits settings, setup and engine actions with canManageSettings()', function () {
        app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
        app(KeyResolver::class)->store('openai', 'sk');
        AiVisibilityPlugin::get()->canManageSettings(fn ($user) => $user->email === 'boss@acme.test');

        expect(ManageSettings::canAccess())->toBeFalse()
            ->and(Setup::canAccess())->toBeFalse()
            ->and(Health::canAccess())->toBeTrue()
            ->and(BrandResource::canAccess())->toBeTrue();

        $this->get(ManageSettings::getUrl())->assertForbidden();
        $this->get(Setup::getUrl())->assertForbidden();
        $this->get(Health::getUrl())
            ->assertOk()
            ->assertDontSee('Change key / settings')
            ->assertDontSee(ManageSettings::getUrl());

        livewire(Health::class)->call('pauseEngine', 'openai')->assertForbidden();

        expect(app(EngineManager::class)->isUsable('openai'))->toBeTrue();
    });

    it('limits all screens with authorizeUsing()', function () {
        AiVisibilityPlugin::get()->authorizeUsing(fn ($user) => false);

        expect(BrandResource::canAccess())->toBeFalse()
            ->and(ResultResource::canAccess())->toBeFalse()
            ->and(Overview::canAccess())->toBeFalse()
            ->and(ManageSettings::canAccess())->toBeFalse();

        $this->get(BrandResource::getUrl())->assertForbidden();
    });
});

describe('alert recipients', function () {
    it('offers only the current user until you search', function () {
        $other = $this->createUser(['name' => 'Bob Builder', 'email' => 'bob@acme.test']);

        expect(ManageSettings::alertRecipientOptions())->toBe([$this->user->id => 'Ada Admin'])
            ->and(ManageSettings::alertRecipientOptions('bob'))->toBe([$other->id => 'Bob Builder']);
    });

    it('uses the alertRecipientsQuery() option and rejects users outside it', function () {
        $colleague = $this->createUser(['name' => 'Cleo Colleague', 'email' => 'cleo@acme.test']);
        $outsider = $this->createUser(['name' => 'Olly Outsider', 'email' => 'olly@other.test']);

        AiVisibilityPlugin::get()->alertRecipientsQuery(fn ($query) => $query->where('email', 'like', '%@acme.test'));

        expect(ManageSettings::alertRecipientOptions())->toHaveKeys([$this->user->id, $colleague->id])
            ->not->toHaveKey($outsider->id)
            ->and(ManageSettings::alertRecipientOptions('olly'))->toBe([]);

        livewire(ManageSettings::class)
            ->fillForm(['alerts' => ['database' => true, 'user_ids' => [$outsider->id]]])
            ->call('save')
            ->assertHasFormErrors(['alerts.user_ids.0']);

        livewire(ManageSettings::class)
            ->fillForm(['alerts' => ['database' => true, 'user_ids' => [$colleague->id]]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(Settings::class)->get('alerts.user_ids'))->toBe([$colleague->id]);
    });
});

describe('navigation', function () {
    it('splits the screens into reports, tracking and admin groups', function () {
        expect(Overview::getNavigationGroup())->toBe('AI Visibility')
            ->and(BrandResource::getNavigationGroup())->toBe('AI Visibility · Tracking')
            ->and(ResultResource::getNavigationGroup())->toBe('AI Visibility · Tracking')
            ->and(AlertRuleResource::getNavigationGroup())->toBe('AI Visibility · Admin')
            ->and(ManageSettings::getNavigationGroup())->toBe('AI Visibility · Admin');
    });

    it('keeps a single group with navigationGroup() or navigationGroups(false)', function () {
        AiVisibilityPlugin::get()->navigationGroup('Marketing');

        expect(BrandResource::getNavigationGroup())->toBe('Marketing')
            ->and(ManageSettings::getNavigationGroup())->toBe('Marketing');

        AiVisibilityPlugin::get()->navigationGroup('AI Visibility')->navigationGroups(false);

        expect(BrandResource::getNavigationGroup())->toBe('AI Visibility');
    });
});
