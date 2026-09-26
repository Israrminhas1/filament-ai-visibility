<?php

use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Engines\KeyResolver;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSync;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceTestResult;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Prompts\PromptGenerator;
use IsrarMinhas\FilamentAiVisibility\Prompts\PromptQualityGate;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $contents);

    return $path;
}

function sourceConnection($brand, string $type = 'serpapi', array $config = []): Connection
{
    return Connection::query()->create([
        'brand_id' => $brand->id, 'type' => $type, 'name' => $type,
        'credentials' => ['api_key' => 'serp-secret'], 'config' => $config, 'status' => Connection::CONNECTED,
    ]);
}

function generatorReply(array $prompts)
{
    return Http::response([
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['prompts' => $prompts])]]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
    ]);
}

beforeEach(function () {
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com'], 'market' => 'UK']);
});

describe('CSV import', function () {
    it('reads a UTF-8 file with a BOM, including our own prompt export', function () {
        expect(Importer::readCsv(csvFile("\xEF\xBB\xBFkeyword,search_volume\ncrm for agencies,1200\n"), 'keyword'))
            ->toBe([['keyword' => 'crm for agencies', 'search_volume' => '1200']]);

        // The prompts export writes a BOM and a "Prompt" column.
        $export = csvFile("\xEF\xBB\xBFBrand,Prompt,Topic,Intent\nAcme,Best CRM for agencies?,Pricing,discovery\n");

        expect(Importer::readCsv($export, 'text'))->toBe([
            ['brand' => 'Acme', 'text' => 'Best CRM for agencies?', 'topic' => 'Pricing', 'intent' => 'discovery'],
        ]);
    });

    it('converts Windows-1252 and UTF-16 files to UTF-8 instead of dropping rows', function () {
        $rows = Importer::readCsv(csvFile("keyword\ncaf\xE9 crm\nprice \x80 crm\n"), 'keyword');

        expect($rows)->toBe([['keyword' => 'café crm'], ['keyword' => 'price € crm']])
            ->and(app(Importer::class)->keywords($this->brand, $rows))->toBe(['created' => 2, 'skipped' => 0])
            ->and(Keyword::query()->pluck('keyword')->sort()->values()->all())->toBe(['café crm', 'price € crm']);

        $utf16 = csvFile("\xFF\xFE" . mb_convert_encoding("keyword\nnaïve crm\n", 'UTF-16LE', 'UTF-8'));

        expect(Importer::readCsv($utf16, 'keyword'))->toBe([['keyword' => 'naïve crm']]);
    });

    it('counts unreadable rows separately from duplicates', function () {
        expect(app(Importer::class)->keywords($this->brand, ["bad \xC3\x28 bytes", 'crm', 'CRM']))
            ->toBe(['created' => 1, 'skipped' => 1, 'invalid' => 1]);
    });

    it('skips over-long keywords and shortens over-long topic names', function () {
        expect(app(Importer::class)->keywords($this->brand, [str_repeat('crm ', 70), 'crm']))
            ->toBe(['created' => 1, 'skipped' => 0, 'too_long' => 1]);

        $result = app(Importer::class)->prompts($this->brand, [['text' => 'Best CRM for agencies?', 'topic' => str_repeat('a', 300)]]);

        expect($result)->toBe(['created' => 1, 'skipped' => 0, 'paused' => 0, 'truncated' => 1])
            ->and(mb_strlen(Topic::query()->value('name')))->toBe(255);
    });

    it('imports nothing when a row fails part way', function () {
        Keyword::saving(function (Keyword $keyword) {
            if ($keyword->keyword === 'boom') {
                throw new RuntimeException('Database went away');
            }
        });

        expect(fn () => app(Importer::class)->keywords($this->brand, ['crm', 'boom', 'best crm']))->toThrow(RuntimeException::class)
            ->and(Keyword::query()->count())->toBe(0);

        Prompt::saving(function (Prompt $prompt) {
            if ($prompt->text === 'Boom?') {
                throw new RuntimeException('Database went away');
            }
        });

        expect(fn () => app(Importer::class)->prompts($this->brand, ['Best CRM?', ['text' => 'Boom?', 'topic' => 'Pricing']]))->toThrow(RuntimeException::class)
            ->and(Prompt::query()->count())->toBe(0)
            ->and(Topic::query()->count())->toBe(0);
    });

    it('parses spreadsheet numbers', function (mixed $value, ?int $expected) {
        expect(Importer::number($value))->toBe($expected);
    })->with([
        ['1.2K', 1200],
        ['3M', 3000000],
        ['12,500', 12500],
        ['12.5', 12],
        ['13.5', 14],
        ['12.7', 13],
        ['1,234.56', 1235],
        ['1.234,5', 1234],
        ['1.200.000', 1200000],
        ['2.5k', 2500],
        [900, 900],
        ['', null],
        ['n/a', null],
    ]);
});

describe('prompt generation intents', function () {
    beforeEach(function () {
        app(KeyResolver::class)->store('openai', 'sk');
        app(Settings::class)->set(['generation' => ['ai_review' => false]]);
    });

    it('only uses requested intents and never lets the brand name through otherwise', function () {
        Http::fake(['api.openai.com/*' => generatorReply([
            ['text' => 'Is Acme a good CRM for agencies?', 'intent' => 'branded'],
            ['text' => 'Which CRM is better for agencies, Pipedrive or HubSpot?', 'intent' => 'comparison'],
        ])]);

        $candidates = app(PromptGenerator::class)->generate($this->brand, intents: ['discovery'], save: false)['candidates'];

        expect($candidates->pluck('intent')->all())->toBe([PromptIntent::Discovery, PromptIntent::Discovery])
            ->and($candidates[0]['passed'])->toBeFalse()
            ->and($candidates[0]['reason'])->toContain('Names the brand')
            ->and($candidates[1]['passed'])->toBeTrue();
    });

    it('allows the brand name only when branded prompts were requested', function () {
        Http::fake(['api.openai.com/*' => generatorReply([
            ['text' => 'Is Acme a good CRM for agencies?', 'intent' => 'discovery'],
            ['text' => 'What is the best CRM for a small agency?', 'intent' => 'branded'],
        ])]);

        $candidates = app(PromptGenerator::class)->generate($this->brand, intents: ['discovery', 'branded'], save: false)['candidates'];

        expect($candidates->pluck('intent')->all())->toBe([PromptIntent::Branded, PromptIntent::Branded])
            ->and($candidates->every(fn ($candidate) => $candidate['passed']))->toBeTrue();

        expect(app(PromptQualityGate::class)->check('Is Acme a good CRM for agencies?', $this->brand, PromptIntent::Branded, [], allowBrand: false))
            ->toContain('Names the brand');
    });
});

describe('prompt quality rules', function () {
    it('accepts requests and short search-style queries', function (string $text) {
        expect(app(PromptQualityGate::class)->check($text, $this->brand, PromptIntent::Discovery, []))->toBeNull();
    })->with([
        'Tell me the best CRM for agencies',
        'Show me CRMs with Slack integration',
        'Explain how agency CRMs handle retainers',
        'Recommend a CRM for a 10-person agency',
        'Compare Pipedrive and HubSpot for agencies',
        'List CRMs with a free plan',
        'Help me pick a CRM for my agency',
        'I need a CRM that tracks client projects',
        'Looking for a cheap CRM for freelancers',
        'crm software for agencies in london',
        'best crm for the uk',
    ]);

    it('still rejects junk', function (string $text, string $reason) {
        expect(app(PromptQualityGate::class)->check($text, $this->brand, PromptIntent::Discovery, []))->toContain($reason);
    })->with([
        ['', 'Too short'],
        ['supercalifragilistic', 'Too short'],
        ['https://example.com/best-crm-for-agencies', 'Just a link'],
        ['www.example.com/crm', 'Just a link'],
        ['1234 5678 9012 3456', 'Not a question'],
    ]);
});

describe('keyword source sync', function () {
    it('does not use its own People also ask results as seeds and puts keywords without volume last', function () {
        $this->brand->keywords()->create(['keyword' => 'crm for agencies']);
        $this->brand->keywords()->create(['keyword' => 'agency crm', 'search_volume' => 500]);
        $this->brand->keywords()->create(['keyword' => 'what is an agency crm?', 'search_volume' => 9000, 'source' => 'serpapi_paa']);
        $connection = sourceConnection($this->brand, config: ['max_seeds' => 5]);

        Http::fake(['serpapi.com/search.json*' => Http::response(['related_questions' => []])]);

        app(KeywordSync::class)->sync($connection);

        $seeds = collect(Http::recorded())->map(fn ($pair) => $pair[0]['q'])->all();

        expect($seeds)->toBe(['agency crm', 'crm for agencies']);
    });

    it('treats "no results" for a seed as zero results, not a failed sync', function () {
        $this->brand->keywords()->create(['keyword' => 'obscure crm query', 'search_volume' => 10]);
        $this->brand->keywords()->create(['keyword' => 'crm for agencies', 'search_volume' => 5]);
        $connection = sourceConnection($this->brand);

        Http::fake(['serpapi.com/search.json*' => Http::sequence()
            ->push(['search_metadata' => ['status' => 'Success'], 'error' => "Google hasn't returned any results for this query."])
            ->push(['related_questions' => [['question' => 'Which CRM do agencies use?']]])]);

        expect(app(KeywordSync::class)->sync($connection))->toBe(['created' => 1, 'updated' => 0, 'skipped' => 0])
            ->and($connection->fresh()->status)->toBe(Connection::CONNECTED);
    });

    it('never stores the API key from a connection error', function () {
        $this->brand->keywords()->create(['keyword' => 'crm']);
        $connection = sourceConnection($this->brand);

        Http::fake(['serpapi.com/*' => Http::failedConnection()]);

        expect(fn () => app(KeywordSync::class)->sync($connection))->toThrow(SourceFailed::class);

        expect($connection->fresh()->last_error)->toBe('Could not reach SerpAPI.')
            ->and(SourceFailed::redact('cURL error 28 for https://serpapi.com/search.json?q=crm&api_key=abc123&gl=gb'))
            ->toBe('cURL error 28 for https://serpapi.com/search.json?q=crm&api_key=[hidden]&gl=gb')
            ->and((new SourceFailed('Authorization: Bearer ya29.secret-token'))->getMessage())->toBe('Authorization: Bearer [hidden]');
    });

    it('keeps syncing other connections when one throws something unexpected', function () {
        app(KeywordSourceRegistry::class)->register(new class implements KeywordSource
        {
            public function key(): string
            {
                return 'exploding';
            }

            public function label(): string
            {
                return 'Exploding';
            }

            public function description(): string
            {
                return '';
            }

            public function formFields(): array
            {
                return [];
            }

            public function test(Connection $connection): SourceTestResult
            {
                return SourceTestResult::ok();
            }

            public function fetch(Connection $connection): iterable
            {
                throw new RuntimeException('Boom at https://api.example.com/?api_key=serp-secret');
            }
        });

        $this->brand->keywords()->create(['keyword' => 'crm']);
        $broken = sourceConnection($this->brand, 'exploding');
        $working = sourceConnection($this->brand);
        Http::fake(['serpapi.com/*' => Http::response(['related_questions' => [['question' => 'Which CRM is best?']]])]);

        $this->artisan('ai-visibility:sync-keywords')->assertSuccessful();

        $broken->refresh();

        expect($broken->status)->toBe(Connection::ERROR)
            ->and($broken->last_error)->not->toContain('serp-secret')
            ->and($broken->last_error)->toContain('unexpectedly')
            ->and($broken->next_sync_at->isTomorrow())->toBeTrue()
            ->and($working->fresh()->last_synced_at)->not->toBeNull()
            ->and(Keyword::query()->where('keyword', 'Which CRM is best?')->exists())->toBeTrue();
    });
});
