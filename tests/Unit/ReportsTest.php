<?php

use Carbon\CarbonImmutable;
use IsrarMinhas\FilamentAiVisibility\Detection\SourceCategory;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Models\Run;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Reports\Metrics;
use IsrarMinhas\FilamentAiVisibility\Reports\PromptHistory;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportFilters;
use IsrarMinhas\FilamentAiVisibility\Support\CsvExport;

/**
 * Store an answer directly, as the recorder would.
 *
 * @param  array<int, array{0: string, 1: ?int, 2: int}>  $mentions  [type, competitor id, position]
 * @param  array<int, array{0: string, 1: string, 2?: bool}>  $citations  [url, category, is brand]
 */
function answer(Prompt $prompt, string $engine, bool $mentioned, ?int $position = null, array $mentions = [], array $citations = [], $ranAt = null, ResultStatus $status = ResultStatus::Success): Result
{
    $run = Run::query()->firstOrCreate(['brand_id' => $prompt->brand_id], ['results_total' => 0]);

    $result = Result::query()->create([
        'run_id' => $run->id,
        'brand_id' => $prompt->brand_id,
        'prompt_id' => $prompt->id,
        'engine' => $engine,
        'model' => 'm',
        'status' => $status,
        'brand_mentioned' => $mentioned,
        'brand_position' => $position,
        'brand_cited' => collect($citations)->contains(fn ($c) => $c[2] ?? false),
        'ran_at' => $ranAt ?? now(),
    ]);

    foreach ($mentions as [$type, $id, $pos]) {
        $result->mentions()->create(['subject_type' => $type, 'subject_id' => $id, 'name_matched' => 'x', 'position' => $pos]);
    }

    foreach ($citations as $i => $citation) {
        $result->citations()->create([
            'url' => $citation[0],
            'domain' => parse_url($citation[0], PHP_URL_HOST),
            'category' => $citation[1],
            'is_brand' => $citation[2] ?? false,
            'position' => $i + 1,
        ]);
    }

    return $result;
}

beforeEach(function () {
    $this->brand = $this->createBrand(['name' => 'Acme', 'domains' => ['acme.com']]);
    $this->globex = $this->brand->competitors()->create(['name' => 'Globex', 'domains' => ['globex.io']]);
    $this->p1 = $this->brand->prompts()->create(['text' => 'Best CRM?']);
    $this->p2 = $this->brand->prompts()->create(['text' => 'Cheapest CRM?']);

    // This period: 3 of 4 answers mention Acme.
    answer($this->p1, 'openai', true, 1, [['brand', $this->brand->id, 1], ['competitor', $this->globex->id, 2]], [['https://acme.com/crm', 'own', true], ['https://g2.com/crm', 'review_comparison']]);
    answer($this->p1, 'gemini', true, 2, [['competitor', $this->globex->id, 1], ['brand', $this->brand->id, 2]], [['https://g2.com/crm', 'review_comparison']]);
    answer($this->p2, 'openai', true, 1, [['brand', $this->brand->id, 1]], [['https://reddit.com/r/crm', 'forum_community']]);
    answer($this->p2, 'gemini', false, null, [['competitor', $this->globex->id, 1]]);

    // Skipped answers never count.
    answer($this->p2, 'openai', false, status: ResultStatus::Skipped);

    // Previous period: p2 was never mentioned, p1 always.
    answer($this->p1, 'openai', true, 3, [['brand', $this->brand->id, 3]], ranAt: now()->subDays(40));
    answer($this->p2, 'openai', false, ranAt: now()->subDays(40));

    Usage::query()->create(['brand_id' => $this->brand->id, 'engine' => 'openai', 'purpose' => 'tracking', 'cost_usd' => 0.25, 'created_at' => now()]);

    $this->filters = ReportFilters::fromState(['brand' => $this->brand->id, 'period' => 30]);
});

it('summarises visibility, citations, position, share of voice and spend', function () {
    expect(app(Metrics::class)->summary($this->filters))->toBe([
        'answers' => 4,
        'visibility' => 75.0,
        'citation_rate' => 25.0,
        'avg_position' => 1.3,
        'share_of_voice' => 50.0,
        'spend' => 0.25,
    ]);
});

it('filters by engine and topic', function () {
    $topic = $this->brand->topics()->create(['name' => 'Pricing']);
    $this->p2->update(['topic_id' => $topic->id]);

    $metrics = app(Metrics::class);

    expect($metrics->summary(ReportFilters::fromState(['brand' => $this->brand->id, 'engine' => 'gemini']))['visibility'])->toBe(50.0)
        ->and($metrics->summary(ReportFilters::fromState(['brand' => $this->brand->id, 'topic' => $topic->id]))['answers'])->toBe(2);
});

it('calculates share of voice per brand', function () {
    $sov = app(Metrics::class)->shareOfVoice($this->filters);

    expect($sov->map(fn ($row) => [$row['name'], $row['answers'], $row['share']])->all())->toBe([
        ['Acme', 3, 50.0],
        ['Globex', 3, 50.0],
    ]);
});

it('builds a daily trend per engine with gaps for days without answers', function () {
    $trend = app(Metrics::class)->trend($this->filters);

    expect($trend['labels'])->toHaveCount(30)
        ->and(end($trend['series']['openai']))->toBe(100.0)
        ->and(end($trend['series']['gemini']))->toBe(50.0)
        ->and($trend['series']['openai'][0])->toBeNull();
});

it('lists top sources, categories and own pages', function () {
    $metrics = app(Metrics::class);

    expect($metrics->topSources($this->filters)->first())->toMatchArray(['domain' => 'g2.com', 'category' => 'review_comparison', 'answers' => 2])
        ->and($metrics->sourceCategories($this->filters)->sortKeys()->all())->toBe(['forum_community' => 1, 'own' => 1, 'review_comparison' => 2])
        ->and($metrics->ownPagesCited($this->filters)->pluck('url')->all())->toBe(['https://acme.com/crm']);
});

it('compares prompts with the previous period', function () {
    $prompts = app(Metrics::class)->prompts($this->filters)->keyBy('prompt_id');

    expect($prompts[$this->p1->id])->toMatchArray(['visibility' => 100.0, 'previous' => 100.0, 'change' => 0.0])
        ->and($prompts[$this->p2->id])->toMatchArray(['visibility' => 50.0, 'previous' => 0.0, 'change' => 50.0]);
});

it('shows what changed between answers to a prompt', function () {
    $history = app(PromptHistory::class)->forPrompt($this->p1)['openai'];

    // Newest first; the newest answer moved from #3 to #1 and Globex appeared.
    expect($history->first()['changes'])->toContain(
        ['type' => 'good', 'text' => 'Moved up from #3 to #1'],
        ['type' => 'bad', 'text' => 'Globex now mentioned'],
        ['type' => 'neutral', 'text' => 'New source: acme.com'],
    )->and($history->last()['changes'])->toBe([]);
});

it('categorises sources', function () {
    $categories = app(SourceCategory::class);
    $brand = Brand::query()->first();

    expect($categories->categorize('https://docs.acme.com/x', $brand))->toBe('own')
        ->and($categories->categorize('https://globex.io', $brand, false, $this->globex->id))->toBe('competitor')
        ->and($categories->categorize('https://www.reddit.com/r/crm', $brand))->toBe('forum_community')
        ->and($categories->categorize('https://en.wikipedia.org/wiki/CRM', $brand))->toBe('wiki_reference')
        ->and($categories->categorize('https://www.gov.uk/x', $brand))->toBe('government_education')
        ->and($categories->categorize('https://random-blog.net', $brand))->toBe('other');
});

it('makes CSV cells safe for spreadsheets', function () {
    expect(CsvExport::cell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(CsvExport::cell('-1+2'))->toBe("'-1+2")
        ->and(CsvExport::cell(true))->toBe('yes')
        ->and(CsvExport::cell('Best CRM?'))->toBe('Best CRM?');
});

it('defaults report filters to the first brand and 30 days', function () {
    $filters = ReportFilters::fromState(null);

    expect($filters->brand->is($this->brand))->toBeTrue()
        ->and($filters->days())->toBe(30)
        ->and($filters->previous()->until->lt($filters->from))->toBeTrue();
});
