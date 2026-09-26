<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Setup;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    app(Settings::class)->record()->forceFill(['setup_completed_at' => null, 'setup_step' => 7])->save();
});

function promptTexts($brand): array
{
    return $brand->activePrompts()->orderBy('id')->pluck('text')->all();
}

it('swaps two prompts\' texts without a unique-constraint error', function () {
    $a = $this->brand->prompts()->create(['text' => 'What is the best CRM for agencies?']);
    $b = $this->brand->prompts()->create(['text' => 'Which CRM works with Slack?']);

    livewire(Setup::class)
        ->fillForm(['prompts' => [
            ['id' => $a->id, 'text' => 'Which CRM works with Slack?'],
            ['id' => $b->id, 'text' => 'What is the best CRM for agencies?'],
        ]])
        ->goToWizardStep(8)
        ->assertWizardCurrentStep(8);

    expect(Prompt::query()->count())->toBe(2)
        ->and(promptTexts($this->brand))->toBe(['What is the best CRM for agencies?', 'Which CRM works with Slack?']);
});

it('merges two rows edited to the same text', function () {
    $a = $this->brand->prompts()->create(['text' => 'What is the best CRM for agencies?']);
    $b = $this->brand->prompts()->create(['text' => 'Which CRM works with Slack?']);

    livewire(Setup::class)
        ->fillForm(['prompts' => [
            ['id' => $a->id, 'text' => 'Cheapest CRM for startups?'],
            ['id' => $b->id, 'text' => 'cheapest crm for startups'],
        ]])
        ->goToWizardStep(8)
        ->assertWizardCurrentStep(8);

    expect(promptTexts($this->brand))->toBe(['Cheapest CRM for startups?'])
        ->and(Prompt::query()->find($b->id))->toBeNull();
});

it('turns a paused prompt back on when its text is typed again', function () {
    $paused = $this->brand->prompts()->create(['text' => 'Which CRM works with Slack?', 'status' => PromptStatus::Paused]);
    $edited = $this->brand->prompts()->create(['text' => 'What is the best CRM for agencies?']);

    livewire(Setup::class)
        ->fillForm(['prompts' => [
            ['id' => $edited->id, 'text' => 'Which CRM works with Slack?'],
            ['id' => null, 'text' => 'Cheapest CRM for startups?'],
        ]])
        ->goToWizardStep(8)
        ->assertNotified('Added 1 prompts');

    expect($paused->fresh()->status)->toBe(PromptStatus::Active)
        ->and(Prompt::query()->find($edited->id))->toBeNull()
        ->and(promptTexts($this->brand))->toBe(['Which CRM works with Slack?', 'Cheapest CRM for startups?']);
});

it('keeps prompts over the active limit listed as paused', function () {
    app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 2]]);

    $component = livewire(Setup::class)
        ->fillForm(['prompts' => [
            ['id' => null, 'text' => 'What is the best CRM for agencies?'],
            ['id' => null, 'text' => 'Which CRM works with Slack?'],
            ['id' => null, 'text' => 'Cheapest CRM for startups?'],
        ]])
        ->goToWizardStep(8)
        ->assertNotified('Added 3 prompts');

    $rows = collect($component->get('data.prompts'))->values();

    expect($this->brand->activePrompts()->count())->toBe(2)
        ->and($rows->pluck('text')->all())->toBe(['What is the best CRM for agencies?', 'Which CRM works with Slack?', 'Cheapest CRM for startups?'])
        ->and($rows->pluck('paused')->all())->toBe([false, false, true]);

    // Removing one frees a slot for the paused question on the next save.
    $component
        ->fillForm(['prompts' => $rows->slice(1)->map(fn ($row) => ['id' => $row['id'], 'text' => $row['text']])->values()->all()])
        ->goToWizardStep(8);

    expect(promptTexts($this->brand))->toBe(['Which CRM works with Slack?', 'Cheapest CRM for startups?'])
        ->and(collect($component->get('data.prompts'))->pluck('paused')->all())->toBe([false, false]);
});

it('counts only questions that were really added when generating', function () {
    app(KeyResolver::class)->store('openai', 'sk');
    app(Settings::class)->set(['generation' => ['ai_review' => false]]);
    Http::fake(['api.openai.com/*' => Http::response([
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['prompts' => [
            ['text' => 'Which CRM integrates best with Slack?', 'intent' => 'discovery'],
            ['text' => 'What is the cheapest CRM for a small agency?', 'intent' => 'discovery'],
        ]])]]]],
        'usage' => [],
    ])]);

    livewire(Setup::class)
        ->fillForm(['prompts' => [['id' => null, 'text' => 'Which CRM integrates best with Slack?']]])
        ->callAction(TestAction::make('generatePrompts')->schemaComponent('promptActions', schema: 'form'))
        ->assertNotified('Added 1 questions');
})->skip(fn () => ! method_exists(TestAction::class, 'schemaComponent'), 'Needs schema component action testing');
