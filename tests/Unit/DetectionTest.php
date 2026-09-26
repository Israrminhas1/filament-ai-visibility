<?php

use IsrarMinhas\FilamentAiVisibility\Detection\CitationExtractor;
use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Support\AnswerHighlighter;
use IsrarMinhas\FilamentAiVisibility\Support\Pricing;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

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

    expect($pricing->cost('gemini', 'gemini-3.8-flash', 0, 0, searches: 3))->toBe(0.042)
        ->and($pricing->cost('gemini', 'gemini-2.5-flash-002', 0, 0, searches: 1))->toBe(0.035)
        ->and($pricing->cost('openai', 'gpt-5-mini', 1_000_000, 0))->toBe(0.25)
        ->and($pricing->cost('openai', 'gpt-5-mini-2025-08-07', 0, 1_000_000, searches: 2))->toBe(2.02)
        ->and($pricing->cost('anthropic', 'claude-future-9', 1_000_000, 0))->toBe(3.0);
});

describe('everyday and legal names', function () {
    it('matches the everyday name when the brand is saved under its legal name', function () {
        $brand = $this->createBrand(['name' => 'Nintendo Co., Ltd.', 'domains' => ['nintendo.com']]);

        $mentions = app(MentionDetector::class)->detect('For kids, the Nintendo Switch is best.', $brand, []);

        expect($mentions)->toHaveCount(1)
            ->and($mentions[0]->subjectType)->toBe('brand')
            ->and($mentions[0]->nameMatched)->toBe('Nintendo');
    });

    it('matches competitors by short name and domain label', function () {
        $brand = $this->createBrand(['name' => 'Nintendo Co., Ltd.', 'domains' => ['nintendo.com']]);
        $sony = $brand->competitors()->create(['name' => 'Sony Interactive Entertainment', 'domains' => ['sony.com']]);
        $valve = $brand->competitors()->create(['name' => 'Valve Corporation']);

        $mentions = app(MentionDetector::class)->detect('Sony and Valve both sell handhelds. Sony is pricier.', $brand, [$sony, $valve]);

        expect(array_map(fn ($m) => [$m->subjectId, $m->count], $mentions))->toBe([[$sony->id, 2], [$valve->id, 1]]);
    });

    it('strips legal suffixes but never leaves a too-short name', function () {
        expect(Text::shortName('Nintendo Co., Ltd.'))->toBe('Nintendo')
            ->and(Text::shortName('Acme Holdings, Inc.'))->toBe('Acme')
            ->and(Text::shortName('Ferrari S.p.A.'))->toBe('Ferrari')
            ->and(Text::shortName('Acme Pty Ltd'))->toBe('Acme')
            ->and(Text::shortName('Siemens AG'))->toBe('Siemens')
            ->and(Text::shortName('Foo L.L.C.'))->toBe('Foo')
            ->and(Text::shortName('HP Inc.'))->toBeNull()
            ->and(Text::shortName('Company'))->toBeNull()
            ->and(Text::shortName('Costco'))->toBeNull()
            ->and(Text::matchTerms(['Nintendo Co., Ltd.'], ['nintendo.com']))->toBe(['Nintendo Co., Ltd.', 'Nintendo'])
            ->and(Text::matchTerms(['Initech'], ['in.com', 'globex.io']))->toBe(['Initech']);
    });
});

describe('detection edge cases', function () {
    it('lets the longest name claim overlapping text across subjects', function () {
        $pro = $this->brand->competitors()->create(['name' => 'Acme Cloud Pro']);

        $mentions = app(MentionDetector::class)->detect('Acme Cloud Pro is new. Acme Cloud is older.', $this->brand, [$pro]);

        expect(array_map(fn ($m) => [$m->subjectType, $m->nameMatched, $m->count], $mentions))->toBe([
            ['competitor', 'Acme Cloud Pro', 1],
            ['brand', 'Acme Cloud', 1],
        ]);

        $brand = $this->createBrand(['name' => 'Google Analytics']);
        $google = $brand->competitors()->create(['name' => 'Google']);

        $mentions = app(MentionDetector::class)->detect('Use Google Analytics, or Google Tag Manager.', $brand, [$google]);

        expect(array_map(fn ($m) => [$m->subjectType, $m->count], $mentions))->toBe([['brand', 1], ['competitor', 1]]);
    });

    it('matches names in scripts written without spaces', function () {
        $brand = $this->createBrand(['name' => '任天堂', 'aliases' => ['Nintendo']]);
        $sony = $brand->competitors()->create(['name' => 'ソニー']);

        $mentions = app(MentionDetector::class)->detect('子供には任天堂のスイッチが一番です。ソニーの製品は高い。Nintendoは人気。', $brand, [$sony]);

        expect(array_map(fn ($m) => [$m->subjectType, $m->count], $mentions))->toBe([['brand', 2], ['competitor', 1]])
            ->and(Text::mentionsAny('삼성은 좋다', ['삼성']))->toBeTrue()
            ->and(Text::mentionsAny('Acmeville', ['Acme']))->toBeFalse();
    });

    it('normalises curly quotes and survives invalid UTF-8', function () {
        $brand = $this->createBrand(['name' => "McDonald's"]);

        expect(app(MentionDetector::class)->detect('McDonald’s is fast.', $brand, []))->toHaveCount(1)
            ->and(app(MentionDetector::class)->detect("Acme \xC3\x28 is great.", $this->brand, []))->toHaveCount(1)
            ->and(Text::mentionsAny("Acme \xFF works", ['Acme']))->toBeTrue();
    });

    it('ignores names inside email addresses and bare domains', function () {
        $notion = $this->brand->competitors()->create(['name' => 'Notion']);

        $answer = 'Email support@acme.io, see acme.com/pricing or notion.so for details.';

        expect(app(MentionDetector::class)->detect($answer, $this->brand, [$notion]))->toBe([]);

        $monday = $this->createBrand(['name' => 'Monday.com']);

        expect(app(MentionDetector::class)->detect('Try Monday.com for boards.', $monday, []))->toHaveCount(1);
    });
});

describe('answer highlighting', function () {
    it('highlights short names, escaped apostrophes and active competitors only, respecting exclusions', function () {
        $brand = $this->createBrand(['name' => "McDonald's Corporation", 'exclusions' => ["McDonald's farm"]]);
        $brand->competitors()->create(['name' => 'Burger King']);
        $brand->competitors()->create(['name' => 'Wendy', 'is_active' => false]);

        $result = (new Result(['answer' => "McDonald’s beats **Burger King** & Wendy. Old McDonald's farm."]))->setRelation('brand', $brand);

        $html = app(AnswerHighlighter::class)->html($result);

        expect(substr_count($html, '<mark'))->toBe(2)
            ->and($html)->toContain('>McDonald&#039;s</mark>')
            ->and($html)->toContain('>Burger King</mark>')
            ->and($html)->toContain('&amp; Wendy')
            ->and($html)->toContain('<strong>');
    });

    it('highlights non-ASCII names in any case', function () {
        $brand = $this->createBrand(['name' => 'Émile']);

        $html = app(AnswerHighlighter::class)->html((new Result(['answer' => 'ÉMILE and émile.']))->setRelation('brand', $brand));

        expect(substr_count($html, '<mark'))->toBe(2);
    });
});

describe('citations and domains', function () {
    it('normalises citation URLs for de-duplication and trims markdown', function () {
        $response = new EngineResponse(
            answer: "See **https://acme.com/pricing/?utm_source=openai** and http://www.acme.com/pricing.\n"
                . "[Wiki](https://en.wikipedia.org/wiki/Acme_(company)) and (https://en.wikipedia.org/wiki/Globex_(film)).\n"
                . 'Also https://globex.io/a?id=5&fbclid=x#top',
            citations: [['url' => 'https://acme.com/pricing?utm_source=openai&utm_medium=x', 'title' => 'Pricing']],
            model: 'm',
        );

        $urls = array_column(app(CitationExtractor::class)->extract($response), 'url');

        expect($urls)->toBe([
            'https://acme.com/pricing',
            'https://en.wikipedia.org/wiki/Acme_(company)',
            'https://en.wikipedia.org/wiki/Globex_(film)',
            'https://globex.io/a?id=5',
        ]);
    });

    it('treats hosting platforms and second-level country domains as suffixes', function () {
        expect(Domains::registrable('https://acme.github.io/docs'))->toBe('acme.github.io')
            ->and(Domains::registrable('blog.acme.netlify.app'))->toBe('acme.netlify.app')
            ->and(Domains::registrable('acme.substack.com'))->toBe('acme.substack.com')
            ->and(Domains::registrable('shop.acme.com.de'))->toBe('acme.com.de')
            ->and(Domains::registrable('writer.medium.com'))->toBe('medium.com');
    });
});

it('builds candidate keys that match however the database compares accents', function () {
    expect(\IsrarMinhas\FilamentAiVisibility\Support\Text::foldKey('Pokémon!'))->toBe('pokemon')
        ->and(\IsrarMinhas\FilamentAiVisibility\Support\Text::foldKey('任天堂'))->toBe('任天堂')
        ->and(\IsrarMinhas\FilamentAiVisibility\Competitors\Discovery::keyFor('name:pokémon'))->toBe('name:pokemon')
        ->and(\IsrarMinhas\FilamentAiVisibility\Competitors\Discovery::keyFor('domain:pokémon.com'))->toBe('domain:pokémon.com');
});
