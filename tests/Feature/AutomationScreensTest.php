<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertType;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertEventResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertEventResource\Pages\ListAlertEvents;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource\Pages\ManageAlertRules;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource\Pages\ManageReportSchedules;
use IsrarMinhas\FilamentAiVisibility\Models\AlertEvent;
use IsrarMinhas\FilamentAiVisibility\Models\AlertRule;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportMail;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    $this->actingAs($this->createUser());
    $this->completeSetup();
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
});

it('creates an alert rule with its own settings', function () {
    livewire(ManageAlertRules::class)
        ->callAction('create', [
            'type' => AlertType::VisibilityDrop->value,
            'brand_id' => $this->brand->id,
            'config' => ['days' => 14, 'points' => 5],
            'channels' => ['slack'],
            'cooldown_hours' => 12,
        ])
        ->assertHasNoFormErrors();

    $rule = AlertRule::query()->first();

    expect($rule->type)->toBe(AlertType::VisibilityDrop)
        ->and($rule->option('days'))->toBe(14)
        ->and($rule->channels)->toBe(['slack']);

    $this->get(AlertRuleResource::getUrl())->assertOk()->assertSee('Visibility drops');
});

it('shows the alerts inbox with an unread badge', function () {
    $event = AlertEvent::query()->create(['type' => 'visibility_drop', 'title' => 'Acme: visibility dropped', 'level' => 'danger', 'url' => 'https://example.com']);

    expect(AlertEventResource::getNavigationBadge())->toBe('1');

    livewire(ListAlertEvents::class)
        ->assertCanSeeTableRecords([$event])
        ->callAction(TestAction::make('markRead')->table($event));

    expect($event->fresh()->read_at)->not->toBeNull()
        ->and(AlertEventResource::getNavigationBadge())->toBeNull();

    $this->get(AlertEventResource::getUrl())->assertOk();
});

it('creates, previews and sends a scheduled report', function () {
    livewire(ManageReportSchedules::class)
        ->callAction('create', [
            'brand_id' => $this->brand->id,
            'name' => 'Weekly for the team',
            'frequency' => 'weekly',
            'recipients' => ['team@example.com'],
            'sections' => ['summary', 'competitors'],
        ])
        ->assertHasNoFormErrors();

    $schedule = ReportSchedule::query()->first();

    expect($schedule->next_send_at->isMonday())->toBeTrue()
        ->and($schedule->sections)->toBe(['summary', 'competitors']);

    livewire(ManageReportSchedules::class)
        ->mountAction(TestAction::make('preview')->table($schedule))
        ->assertMountedActionModalSeeHtml('AI visibility report');

    livewire(ManageReportSchedules::class)
        ->callAction(TestAction::make('sendNow')->table($schedule))
        ->assertNotified('Report sent');

    Mail::assertSent(ReportMail::class);
    $this->get(ReportScheduleResource::getUrl())->assertOk();
});
