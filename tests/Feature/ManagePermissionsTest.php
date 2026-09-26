<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineManager;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\ManageSettings;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Overview;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\CreateBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\BrandResource\Pages\EditBrand;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource\Pages\ManageConnections;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Widgets\PausedEngines;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

/**
 * The README's example callbacks, which dereference the user.
 */
function onlyTheBoss(): void
{
    AiVisibilityPlugin::get()
        ->authorizeUsing(fn ($user) => str_ends_with($user->email, '@acme.test'))
        ->canManageSettings(fn ($user) => $user->email === 'boss@acme.test');
}

describe('guests', function () {
    it('sends guests to the login instead of calling the callbacks with no user', function () {
        onlyTheBoss();

        expect(app(Settings::class)->isSetupComplete())->toBeFalse()
            ->and(AiVisibilityPlugin::get()->isAuthorized())->toBeFalse()
            ->and(AiVisibilityPlugin::get()->canManage())->toBeFalse();

        $this->withoutExceptionHandling();

        expect(fn () => $this->get(Overview::getUrl()))->toThrow(AuthenticationException::class);
    });

    it('still sends a manager to setup while it is incomplete', function () {
        onlyTheBoss();
        $this->actingAs($this->createUser(['email' => 'boss@acme.test']));

        $this->get(Overview::getUrl())->assertRedirect(Setup::getUrl());
    });

    it('lets a non-manager through while setup is incomplete', function () {
        onlyTheBoss();
        $this->actingAs($this->createUser(['email' => 'staff@acme.test']));

        $this->get(Overview::getUrl())->assertOk();
    });
});

describe('what canManageSettings() gates', function () {
    beforeEach(function () {
        $this->completeSetup();
        onlyTheBoss();
        $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com'], 'settings' => ['budget' => ['monthly_usd' => 50]]]);
        $this->actingAs($this->createUser(['email' => 'staff@acme.test']));
    });

    it('forbids alert rules and scheduled reports', function () {
        expect(AlertRuleResource::canAccess())->toBeFalse()
            ->and(ReportScheduleResource::canAccess())->toBeFalse();

        $this->get(AlertRuleResource::getUrl())->assertForbidden();
        $this->get(ReportScheduleResource::getUrl())->assertForbidden();
    });

    it('shows keyword sources but not the actions that use their keys', function () {
        Http::fake();
        $connection = Connection::query()->create(['brand_id' => $this->brand->id, 'type' => 'serpapi', 'name' => 'PAA', 'credentials' => ['api_key' => 'k']]);

        expect(ConnectionResource::canCreate())->toBeFalse()
            ->and(ConnectionResource::canEdit($connection))->toBeFalse()
            ->and(ConnectionResource::canDelete($connection))->toBeFalse();

        $this->get(ConnectionResource::getUrl())->assertOk()->assertSee('PAA');

        livewire(ManageConnections::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('sync')->table($connection))
            ->assertActionHidden(TestAction::make('test')->table($connection))
            ->assertActionHidden(TestAction::make('edit')->table($connection))
            ->assertActionHidden(TestAction::make('delete')->table($connection));

        // Crafted calls to the hidden actions do nothing.
        foreach (['sync', 'test', 'delete'] as $action) {
            livewire(ManageConnections::class)
                ->call('mountAction', $action, [], ['table' => true, 'recordKey' => (string) $connection->getKey()])
                ->call('callMountedAction');
        }

        livewire(ManageConnections::class)
            ->call('mountAction', 'create')
            ->set('mountedActions.0.data', ['brand_id' => $this->brand->id, 'type' => 'serpapi', 'name' => 'Sneaky', 'credentials' => ['api_key' => 'x']])
            ->call('callMountedAction');

        Http::assertNothingSent();

        expect(Connection::query()->pluck('name')->all())->toBe(['PAA'])
            ->and($connection->fresh()->last_synced_at)->toBeNull();

        // The same call works for a manager.
        $this->actingAs($this->createUser(['email' => 'boss@acme.test']));

        livewire(ManageConnections::class)
            ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $connection->getKey()])
            ->call('callMountedAction');

        expect(Connection::query()->count())->toBe(0);
    });

    it('hides the brand settings tab and ignores budget changes from a crafted request', function () {
        $this->get(BrandResource::getUrl('edit', ['record' => $this->brand]))
            ->assertOk()
            ->assertDontSee('Monthly budget for this brand');

        livewire(EditBrand::class, ['record' => $this->brand->getRouteKey()])
            ->assertFormFieldDoesNotExist('settings.budget.monthly_usd')
            ->set('data.name', 'Acme Ltd')
            ->set('data.settings.budget.monthly_usd', 5000)
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->brand->fresh())
            ->name->toBe('Acme Ltd')
            ->settings->toBe(['budget' => ['monthly_usd' => 50]]);

        livewire(CreateBrand::class)
            ->set('data.name', 'Globex')
            ->set('data.domains', ['globex.com'])
            ->set('data.settings.budget.monthly_usd', 5000)
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Brand::query()->where('name', 'Globex')->first()->settings)->toBeNull();
    });

    it('lets a manager change the brand settings', function () {
        $this->actingAs($this->createUser(['email' => 'boss@acme.test']));

        livewire(EditBrand::class, ['record' => $this->brand->getRouteKey()])
            ->assertFormFieldExists('settings.budget.monthly_usd')
            ->fillForm(['settings' => ['budget' => ['monthly_usd' => 75]]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->brand->fresh()->settings)->toBe(['budget' => ['monthly_usd' => 75]]);

        $this->get(AlertRuleResource::getUrl())->assertOk();
        $this->get(ReportScheduleResource::getUrl())->assertOk();
    });
});

it('hides the paused engines banner from users who cannot open AI Visibility', function () {
    $this->completeSetup();
    app(Settings::class)->set(['engines' => ['enabled' => ['openai']]]);
    app(KeyResolver::class)->store('openai', 'sk');
    app(EngineManager::class)->pause('openai', PauseReason::ProviderOutage);
    $this->actingAs($this->createUser(['email' => 'someone@elsewhere.test']));

    expect(PausedEngines::canView())->toBeTrue();

    onlyTheBoss();

    expect(PausedEngines::canView())->toBeFalse();
});

it('drops alert recipients who fell out of scope instead of blocking the save', function () {
    $this->completeSetup();
    $me = $this->createUser(['name' => 'Ada', 'email' => 'ada@acme.test']);
    $outsider = $this->createUser(['name' => 'Olly', 'email' => 'olly@other.test']);
    $this->actingAs($me);

    app(Settings::class)->set(['alerts' => ['database' => true, 'user_ids' => [$me->id, $outsider->id]]]);
    AiVisibilityPlugin::get()->alertRecipientsQuery(fn ($query) => $query->where('email', 'like', '%@acme.test'));

    livewire(ManageSettings::class)
        ->assertNotified('1 alert recipient was removed')
        ->assertSchemaStateSet(['alerts.user_ids' => [$me->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->get('alerts.user_ids'))->toBe([$me->id]);
});

it('puts setup in the admin group', function () {
    expect(Setup::getNavigationGroup())->toBe('AI Visibility · Admin')
        ->and(Overview::getNavigationGroup())->toBe('AI Visibility');
});
