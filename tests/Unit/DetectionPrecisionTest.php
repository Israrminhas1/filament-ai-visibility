<?php

use IsrarMinhas\FilamentAiVisibility\Detection\MentionDetector;
use IsrarMinhas\FilamentAiVisibility\Models\Result;
use IsrarMinhas\FilamentAiVisibility\Support\AnswerHighlighter;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

function detectedNames($test, string $name, array $domains, string $answer): array
{
    $brand = $test->createBrand(['name' => $name, 'domains' => $domains]);

    return array_map(fn ($m) => [$m->nameMatched, $m->count], app(MentionDetector::class)->detect($answer, $brand, []));
}

describe('derived terms match only in the name\'s own casing', function () {
    it('finds the everyday name, capitalised or in capitals', function (string $name, array $domains, string $answer, string $matched) {
        expect(detectedNames($this, $name, $domains, $answer))->toBe([[$matched, 1]]);
    })->with([
        ['Target Corporation', [], 'Shop at Target for basics.', 'Target'],
        ['Target Corporation', [], 'TARGET has a sale.', 'TARGET'],
        ['Gap Inc.', [], 'Gap sells jeans.', 'Gap'],
        ['Next plc', ['next.co.uk'], 'Next is a UK retailer.', 'Next'],
        ['Apple Inc.', [], 'Apple makes phones.', 'Apple'],
        ['Box, Inc.', [], 'Box stores files.', 'Box'],
        ['Notion Labs, Inc.', ['notion.so'], 'Notion is a wiki.', 'Notion'],
        ['Monday.com Ltd.', ['monday.com'], 'Try Monday.com for boards.', 'Monday.com'],
        ['Best Software', ['best.com'], 'Best Software sells CRM.', 'Best Software'],
    ]);

    it('ignores the same words as ordinary words', function (string $name, array $domains, string $answer) {
        expect(detectedNames($this, $name, $domains, $answer))->toBe([]);
    })->with([
        ['Target Corporation', [], 'Know your target audience.'],
        ['Gap Inc.', [], 'Mind the gap.'],
        ['Next plc', ['next.co.uk'], 'The next step is easy.'],
        ['Apple Inc.', [], 'Bake an apple pie.'],
        ['Box, Inc.', [], 'Put it in a box.'],
        ['Notion Labs, Inc.', ['notion.so'], 'I had a notion to try it.'],
        ['Monday.com Ltd.', ['monday.com'], 'See you Monday.'],
        ['Best Software', ['best.com'], 'The best CRM for you.'],
    ]);

    it('still matches typed names and aliases in any case', function () {
        $brand = $this->createBrand(['name' => 'Target Corporation', 'aliases' => ['Bullseye']]);

        expect(app(MentionDetector::class)->detect('target corporation and bullseye.', $brand, []))->toHaveCount(1)
            ->and(app(MentionDetector::class)->detect('target corporation and bullseye.', $brand, [])[0]->count)->toBe(2);
    });

    it('highlights derived terms only in their own casing', function () {
        $brand = $this->createBrand(['name' => 'Apple Inc.']);

        $html = app(AnswerHighlighter::class)->html((new Result(['answer' => 'Apple beats an apple pie.']))->setRelation('brand', $brand));

        expect(substr_count($html, '<mark'))->toBe(1)->and($html)->toContain('>Apple</mark>');
    });

    it('builds derived terms from whole words only, in the name\'s casing', function () {
        expect(Text::terms(['Notion Labs, Inc.'], ['notion.so']))->toBe([['notion labs, inc.' => 'Notion Labs, Inc.'], ['notion labs' => 'Notion Labs', 'notion' => 'Notion']])
            ->and(Text::terms(['Monday.com Ltd.'], ['monday.com'])[1])->toBe(['monday.com' => 'Monday.com'])
            ->and(Text::terms(['Best Software'], ['best.com'])[1])->toBe(['best' => 'Best'])
            ->and(Text::matchTerms(['Initech'], ['init.com']))->toBe(['Initech'])
            ->and(Text::casings('Target'))->toBe(['Target', 'TARGET']);
    });
});

describe('short names', function () {
    it('strips legal suffixes and one generic word, never down to an ordinary word', function () {
        expect(Text::shortName('The Company Ltd'))->toBeNull()
            ->and(Text::shortName('Company Ltd'))->toBeNull()
            ->and(Text::shortName('Global Services Inc.'))->toBeNull()
            ->and(Text::shortName('Next plc'))->toBeNull()
            ->and(Text::shortName('Booking Holdings Inc.'))->toBe('Booking')
            ->and(Text::shortName('Acme Group'))->toBe('Acme')
            ->and(Text::shortName('Acme Group Holdings'))->toBe('Acme Group')
            ->and(Text::shortName('Target Corporation'))->toBe('Target')
            ->and(Text::shortName('Gap Inc.'))->toBe('Gap');
    });

    it('does not match an ordinary word left by a generic name', function () {
        expect(detectedNames($this, 'The Company Ltd', [], 'The best tool.'))->toBe([])
            ->and(detectedNames($this, 'Company Ltd', [], 'Ask the company.'))->toBe([]);
    });
});

describe('brands written as their own domain', function () {
    it('counts the subject\'s own bare domain once', function () {
        expect(detectedNames($this, 'Booking Holdings Inc.', ['booking.com'], 'Booking.com is the largest OTA.'))->toBe([['Booking.com', 1]])
            ->and(detectedNames($this, 'Jasper', ['jasper.ai'], 'Jasper.ai writes copy.'))->toBe([['Jasper.ai', 1]])
            ->and(detectedNames($this, 'Jasper', ['jasper.ai'], 'Try app.jasper.ai today.'))->toBe([['app.jasper.ai', 1]]);
    });

    it('keeps other domains, links and emails masked', function () {
        expect(detectedNames($this, 'Jasper', ['jasper.ai'], 'Jasper.io is someone else.'))->toBe([])
            ->and(detectedNames($this, 'Jasper', ['jasper.ai'], 'See https://jasper.ai/pricing or ask hi@jasper.ai.'))->toBe([])
            ->and(detectedNames($this, 'Jasper', ['jasper.ai'], 'See jasper.ai/pricing.'))->toBe([]);
    });

    it('does not read a missing space after a full stop as a domain', function () {
        expect(detectedNames($this, 'Acme', [], 'I use Acme.It works.'))->toBe([['Acme', 1]])
            ->and(detectedNames($this, 'Acme', [], 'I use acme.it daily.'))->toBe([]);
    });
});

it('folds dotted capitals and sharp s in keys', function () {
    expect(Text::foldKey('İstanbul'))->toBe('istanbul')
        ->and(Text::foldKey('Straße'))->toBe('strasse')
        ->and(Text::foldKey('Pokémon!'))->toBe('pokemon');
});
