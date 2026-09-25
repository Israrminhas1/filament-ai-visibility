<?php

use IsrarMinhas\FilamentAiVisibility\Detection\CitationExtractor;
use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Support\Pricing;

beforeEach(function () {
    $this->brand = $this->createBrand(['name' => 'Acme', 'aliases' => ['Acme Cloud'], 'domains' => ['acme.com']]);
    $this->globex = $this->brand->competitors()->create(['name' => 'Globex', 'domains' => ['globex.io']]);
    $this->initech = $this->brand->competitors()->create(['name' => 'Initech', 'aliases' => ['Initrode']]);
});

it('finds mentions in order with counts and snippets', function () {
    $answer = "For agencies, Globex is popular. Many teams prefer Acme Cloud.\nInitrode is cheaper, and Acme has great support.";

    $mentions = app(MentionDetector::class)->detect($answer, $this->brand, [$this->globex, $this->initech]);

    expect(array_map(fn ($m) => [$m->subjectType, $m->nameMatched, $m->position, $m->count], $mentions))->toBe([
        ['competitor', 'Globex', 1, 1],
        ['brand', 'Acme Cloud', 2, 2],
        ['competitor', 'Initrode', 3, 1],
    ])->and($mentions[1]->snippet)->toBe('Many teams prefer Acme Cloud.');
});

it('ignores names inside words, URLs and exclusion phrases', function () {
    $this->brand->update(['exclusions' => ['acme of perfection']]);

    $answer = 'Acmeville is a town. See https://acme.com/pricing and www.acme.com. This is the acme of perfection.';

    expect(app(MentionDetector::class)->detect($answer, $this->brand->fresh(), []))->toBe([]);
});

it('extracts citations from the engine and from links in the answer', function () {
    $response = new EngineResponse(
        answer: 'See [Acme pricing](https://www.acme.com/pricing) and https://blog.globex.co.uk/post. Also (https://acme.com/pricing).',
        citations: [['url' => 'https://g2.com/crm', 'title' => 'G2']],
        model: 'm',
    );

    $citations = app(CitationExtractor::class)->extract($response);

    expect(array_map(fn ($c) => [$c['url'], $c['domain']], $citations))->toBe([
        ['https://g2.com/crm', 'g2.com'],
        ['https://www.acme.com/pricing', 'acme.com'],
        ['https://blog.globex.co.uk/post', 'globex.co.uk'],
    ]);
});

it('resolves registrable domains and subdomain matches', function () {
    expect(Domains::registrable('https://shop.eu.acme.co.uk/x'))->toBe('acme.co.uk')
        ->and(Domains::registrable('docs.acme.com'))->toBe('acme.com')
        ->and(Domains::matches('https://docs.acme.com/a', ['acme.com']))->toBeTrue()
        ->and(Domains::matches('https://notacme.com', ['acme.com']))->toBeFalse();
});

it('prices calls by model, snapshot and search count', function () {
    $pricing = app(Pricing::class);

    expect($pricing->cost('openai', 'gpt-5-mini', 1_000_000, 0))->toBe(0.25)
        ->and($pricing->cost('openai', 'gpt-5-mini-2025-08-07', 0, 1_000_000, searches: 2))->toBe(2.02)
        ->and($pricing->cost('anthropic', 'claude-future-9', 1_000_000, 0))->toBe(3.0);
});
