<?php

use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Exceptions\LimitExceeded;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Competitor;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Support\Importer;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;
use IsrarMinhas\FilamentAiVisibility\Support\Text;
use IsrarMinhas\FilamentAiVisibility\Support\WebsiteProfile;

describe('text', function () {
    it('normalises and hashes near-identical text the same', function () {
        expect(Text::hash('Best CRM for agencies?'))->toBe(Text::hash('  best crm  for agencies '));
    });

    it('matches whole words only, case-insensitively', function () {
        expect(Text::mentionsAny('We recommend Acme and others', ['acme']))->toBeTrue()
            ->and(Text::mentionsAny('Acmeville is a town', ['Acme']))->toBeFalse()
            ->and(Text::mentionsAny('Try Zoë’s CRM', ['Zoë']))->toBeTrue()
            ->and(Text::mentionsAny('anything', ['', ' ']))->toBeFalse();
    });

    it('measures word overlap', function () {
        expect(Text::similarity('best crm for agencies', 'best crm for small agencies'))->toBeGreaterThan(0.7)
            ->and(Text::similarity('best crm', 'cheap flights'))->toBe(0.0);
    });
});

describe('brands', function () {
    it('normalises domains and cleans lists', function () {
        $brand = $this->createBrand([
            'domains' => ['https://www.Acme.com/about', 'acme.com', 'acme.co.uk', 'not a domain'],
            'aliases' => ['Acme Inc', ' acme inc ', ''],
        ]);

        expect($brand->domains)->toBe(['acme.com', 'acme.co.uk'])
            ->and($brand->aliases)->toBe(['Acme Inc'])
            ->and($brand->names())->toBe(['Acme', 'Acme Inc']);
    });

    it('assigns competitor colours in order', function () {
        $brand = $this->createBrand();
        $first = $brand->competitors()->create(['name' => 'One']);
        $second = $brand->competitors()->create(['name' => 'Two']);

        expect($first->color)->toBe(Competitor::PALETTE[0])
            ->and($second->color)->toBe(Competitor::PALETTE[1]);
    });

    it('flags branded keywords', function () {
        $brand = $this->createBrand(['aliases' => ['AcmeHQ']]);

        expect($brand->keywords()->create(['keyword' => 'acmehq pricing'])->is_branded)->toBeTrue()
            ->and($brand->keywords()->create(['keyword' => 'best crm'])->is_branded)->toBeFalse();
    });
});

describe('settings', function () {
    it('falls back to config defaults and saves nested values', function () {
        $settings = app(Settings::class);

        expect($settings->get('runs.samples'))->toBe(1);

        $settings->set(['runs' => ['samples' => 3], 'engines' => ['enabled' => ['openai', 'gemini']]]);
        $settings->set(['engines' => ['enabled' => ['anthropic']]]);

        expect($settings->get('runs.samples'))->toBe(3)
            ->and($settings->get('runs.frequency'))->toBe('weekly')
            ->and($settings->get('engines.enabled'))->toBe(['anthropic']);
    });

    it('lets brands override allowed settings only', function () {
        app(Settings::class)->set(['runs' => ['samples' => 2], 'limits' => ['max_brands' => 5]]);

        $brand = $this->createBrand(['settings' => ['runs' => ['samples' => 4], 'limits' => ['max_brands' => 1]]]);

        expect($brand->setting('runs.samples'))->toBe(4)
            ->and($brand->hasOverride('runs.samples'))->toBeTrue()
            ->and($brand->setting('limits.max_brands'))->toBe(5);
    });
});

describe('limits', function () {
    it('enforces the active prompt limit on every save', function () {
        app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 2]]);
        $brand = $this->createBrand();

        $brand->prompts()->create(['text' => 'one']);
        $brand->prompts()->create(['text' => 'two']);
        $paused = $brand->prompts()->create(['text' => 'three', 'status' => PromptStatus::Paused]);

        expect(fn () => $brand->prompts()->create(['text' => 'four']))->toThrow(LimitExceeded::class)
            ->and(fn () => $paused->update(['status' => PromptStatus::Active]))->toThrow(LimitExceeded::class);

        // Editing an already-active prompt is fine.
        $brand->prompts()->first()->update(['text' => 'one, edited']);
    });

    it('treats 0 as no limit', function () {
        app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 0]]);
        $brand = $this->createBrand();

        foreach (range(1, 60) as $i) {
            $brand->prompts()->create(['text' => "prompt {$i}"]);
        }

        expect($brand->activePrompts()->count())->toBe(60);
    });

    it('enforces brand and competitor limits', function () {
        app(Settings::class)->set(['limits' => ['max_brands' => 1, 'max_competitors_per_brand' => 1]]);
        $brand = $this->createBrand();
        $brand->competitors()->create(['name' => 'One']);

        expect(fn () => $this->createBrand(['name' => 'Second']))->toThrow(LimitExceeded::class)
            ->and(fn () => $brand->competitors()->create(['name' => 'Two']))->toThrow(LimitExceeded::class);
    });
});

describe('importer', function () {
    it('imports prompts, skips duplicates, creates topics and pauses over the limit', function () {
        app(Settings::class)->set(['limits' => ['max_active_prompts_per_brand' => 2]]);
        $brand = $this->createBrand();
        $brand->prompts()->create(['text' => 'Existing prompt?']);

        $result = app(Importer::class)->prompts($brand, [
            'existing prompt',
            ['text' => 'Best CRM for agencies?', 'topic' => 'Agencies', 'intent' => 'comparison', 'tags' => 'crm, agency'],
            'Cheapest CRM?',
            'Cheapest CRM?',
        ]);

        $imported = Prompt::query()->where('text', 'Best CRM for agencies?')->first();

        expect($result)->toBe(['created' => 2, 'skipped' => 2, 'paused' => 1])
            ->and($imported->topic->name)->toBe('Agencies')
            ->and($imported->intent->value)->toBe('comparison')
            ->and($imported->tags)->toBe(['crm', 'agency'])
            ->and(Prompt::query()->where('text', 'Cheapest CRM?')->first()->status)->toBe(PromptStatus::Paused);
    });

    it('reads CSV files with or without a header row', function () {
        $withHeader = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($withHeader, "Keyword,Search Volume\ncrm for agencies,1200\n\"best crm, uk\",300\n");

        $withoutHeader = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($withoutHeader, "crm for agencies\nbest crm\n");

        expect(Importer::readCsv($withHeader, 'keyword'))->toBe([
            ['keyword' => 'crm for agencies', 'search_volume' => '1200'],
            ['keyword' => 'best crm, uk', 'search_volume' => '300'],
        ])->and(Importer::readCsv($withoutHeader, 'keyword'))->toBe([
            ['keyword' => 'crm for agencies'],
            ['keyword' => 'best crm'],
        ]);
    });

    it('imports keywords with metrics and skips duplicates', function () {
        $brand = $this->createBrand();

        $result = app(Importer::class)->keywords($brand, [
            ['keyword' => 'crm for agencies', 'search_volume' => '1,200'],
            'CRM for agencies',
            'best crm',
        ]);

        expect($result)->toBe(['created' => 2, 'skipped' => 1])
            ->and(Keyword::query()->where('keyword', 'crm for agencies')->value('search_volume'))->toBe(1200);
    });
});

describe('tenancy', function () {
    it('scopes brands and their children to the current tenant', function () {
        Tenancy::resolveUsing(fn () => 'team-a');
        $a = $this->createBrand(['name' => 'A']);
        $a->prompts()->create(['text' => 'prompt a']);

        Tenancy::resolveUsing(fn () => 'team-b');
        $b = $this->createBrand(['name' => 'B']);
        $b->prompts()->create(['text' => 'prompt b']);

        expect(Brand::query()->pluck('name')->all())->toBe(['B'])
            ->and(Prompt::query()->pluck('text')->all())->toBe(['prompt b'])
            ->and($b->tenant_id)->toBe('team-b');

        expect(Tenancy::as('team-a', fn () => Prompt::query()->pluck('text')->all()))->toBe(['prompt a']);

        config(['ai-visibility.tenant_support' => false]);

        expect(Brand::query()->count())->toBe(2);
    });

    it('keeps settings per tenant', function () {
        Tenancy::resolveUsing(fn () => 'team-a');
        app(Settings::class)->set(['runs' => ['samples' => 3]]);

        Tenancy::resolveUsing(fn () => 'team-b');

        expect(app(Settings::class)->get('runs.samples'))->toBe(1);
    });
});

it('reads website basics', function () {
    $profile = app(WebsiteProfile::class)->parse('<html lang="en-GB"><head><title>Acme CRM | Simple CRM for agencies</title><meta name="description" content="The CRM agencies love."></head></html>');

    expect($profile)->toBe([
        'name' => 'Acme CRM',
        'title' => 'Acme CRM | Simple CRM for agencies',
        'description' => 'The CRM agencies love.',
        'language' => 'en-GB',
    ]);
});
