<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ConnectionResource\Pages\ManageConnections;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\KeywordResource\Pages\ListKeywords;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs($this->createUser());
    $this->completeSetup();
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
});

it('imports a Windows-1252 CSV with a friendly count of skipped rows', function () {
    $csv = UploadedFile::fake()->createWithContent('keywords.csv', "keyword,search_volume\ncaf\xE9 crm,1.2K\n" . str_repeat('x', 300) . ",5\n");

    livewire(ListKeywords::class)
        ->callAction('addKeywords', ['brand_id' => $this->brand->id, 'csv' => $csv])
        ->assertNotified('Added 1 keywords');

    expect(Keyword::query()->pluck('search_volume', 'keyword')->all())->toBe(['café crm' => 1200]);
});

it('shows a friendly message and imports nothing when the import fails', function () {
    Keyword::saving(function (Keyword $keyword) {
        if ($keyword->keyword === 'boom') {
            throw new RuntimeException('SQLSTATE[HY000]: General error');
        }
    });

    livewire(ListKeywords::class)
        ->callAction('addKeywords', ['brand_id' => $this->brand->id, 'lines' => "crm\nboom\nbest crm"])
        ->assertNotified('Import failed');

    expect(Keyword::query()->count())->toBe(0);
});

it('resets a failed connection when its credentials are changed', function () {
    $connection = Connection::query()->create([
        'brand_id' => $this->brand->id, 'type' => 'serpapi', 'name' => 'PAA', 'credentials' => ['api_key' => 'old'],
    ]);
    $connection->forceFill(['status' => Connection::NEEDS_REAUTH, 'last_error' => 'Invalid API key.', 'next_sync_at' => now()->addDay()])->save();
    Http::fake(['serpapi.com/account.json*' => Http::response(['total_searches_left' => 50])]);

    livewire(ManageConnections::class)
        ->callAction(TestAction::make('edit')->table($connection), ['credentials' => ['api_key' => 'new-key']])
        ->assertHasNoFormErrors()
        ->assertNotified('Saved and connected');

    $connection->refresh();

    expect($connection->status)->toBe(Connection::CONNECTED)
        ->and($connection->last_error)->toBeNull()
        ->and($connection->next_sync_at->isFuture())->toBeFalse()
        ->and($connection->credential('api_key'))->toBe('new-key');
})->skip(fn () => ! method_exists(TestAction::class, 'table'), 'Needs Filament 4+');
