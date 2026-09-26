<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords\Sources;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource as KeywordSourceEnum;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordData;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceTestResult;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;

/**
 * Real question phrasings from Google's "People also ask" and related
 * searches, for seed keywords (the brand's own keywords by default).
 */
class SerpApiPeopleAlsoAsk implements KeywordSource
{
    public function key(): string
    {
        return 'serpapi';
    }

    public function label(): string
    {
        return 'SerpAPI: People also ask';
    }

    public function description(): string
    {
        return 'Questions people actually search for, from Google\'s "People also ask" and related searches. Uses one SerpAPI search per seed keyword.';
    }

    public function formFields(): array
    {
        return [
            TextInput::make('credentials.api_key')
                ->label('SerpAPI key')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->helperText('From serpapi.com/manage-api-key. Leave empty to keep the saved key.'),
            TagsInput::make('config.seeds')
                ->label('Seed keywords')
                ->placeholder('crm for agencies')
                ->helperText('Leave empty to use the brand\'s top keywords.'),
            TextInput::make('config.max_seeds')
                ->label('Max seed keywords per sync')
                ->numeric()
                ->default(10)
                ->minValue(1)
                ->maxValue(100)
                ->helperText('Each seed uses one SerpAPI search.'),
        ];
    }

    public function test(Connection $connection): SourceTestResult
    {
        try {
            $response = Http::timeout(20)->get('https://serpapi.com/account.json', ['api_key' => $connection->credential('api_key')]);
        } catch (ConnectionException) {
            return SourceTestResult::failed('Could not reach SerpAPI.');
        }

        if ($response->failed() || $response->json('error')) {
            return SourceTestResult::failed($response->json('error') ?? 'SerpAPI rejected the key.', credentialsRejected: in_array($response->status(), [401, 403], true));
        }

        $left = $response->json('total_searches_left') ?? $response->json('plan_searches_left');

        return SourceTestResult::ok($left !== null ? "Connected. {$left} searches left this month." : 'Connected.');
    }

    public function fetch(Connection $connection): iterable
    {
        $max = max(1, (int) $connection->setting('max_seeds', 10));
        $seeds = array_values(array_filter((array) $connection->setting('seeds', []), fn ($seed) => is_string($seed) && trim($seed) !== ''));

        if ($seeds === []) {
            // Not questions this source found itself, or each sync would feed on the last one.
            $seeds = Keyword::query()
                ->where('brand_id', $connection->brand_id)
                ->where('status', 'active')
                ->where('is_branded', false)
                ->whereNotIn('source', [KeywordSourceEnum::SerpApiPaa->value, $this->key()])
                // Keywords with metrics first (NULLs last on every database).
                ->orderByRaw('search_volume is null')
                ->orderByDesc('search_volume')
                ->orderByRaw('impressions is null')
                ->orderByDesc('impressions')
                ->orderBy('id')
                ->limit($max)
                ->pluck('keyword')
                ->all();
        }

        $seeds = array_slice($seeds, 0, $max);

        if ($seeds === []) {
            throw new SourceFailed('Add seed keywords to this connection, or add keywords to the brand first.');
        }

        $country = $connection->brand?->countryCode();

        foreach ($seeds as $seed) {
            $response = $this->search($connection, $seed, $country);

            if ($response === null) {
                continue;
            }

            foreach ((array) $response->json('related_questions', []) as $item) {
                if (is_array($item) && is_string($item['question'] ?? null) && filled($item['question'])) {
                    yield new KeywordData($item['question'], metadata: ['seed' => $seed, 'type' => 'people_also_ask']);
                }
            }

            foreach ((array) $response->json('related_searches', []) as $item) {
                if (is_array($item) && is_string($item['query'] ?? null) && filled($item['query'])) {
                    yield new KeywordData($item['query'], metadata: ['seed' => $seed, 'type' => 'related_search']);
                }
            }
        }
    }

    /**
     * The search results, or null when Google found nothing for the seed.
     */
    protected function search(Connection $connection, string $query, ?string $country): ?Response
    {
        try {
            $response = Http::timeout(60)->get('https://serpapi.com/search.json', array_filter([
                'engine' => 'google',
                'q' => $query,
                'gl' => $country ? strtolower($country) : null,
                'api_key' => $connection->credential('api_key'),
            ]));
        } catch (ConnectionException $e) {
            // The exception message quotes the URL, which includes the API key.
            throw new SourceFailed('Could not reach SerpAPI.', previous: $e);
        }

        $error = $response->json('error');

        // "Google hasn't returned any results for this query." is a normal, empty answer.
        if ($response->successful() && is_string($error) && str_contains(strtolower($error), 'returned any results')) {
            return null;
        }

        if ($response->failed() || $error) {
            throw new SourceFailed('SerpAPI: ' . (is_string($error) ? $error : 'HTTP ' . $response->status()), in_array($response->status(), [401, 403], true));
        }

        return $response;
    }
}
