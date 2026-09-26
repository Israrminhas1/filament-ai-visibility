<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords\Sources;

use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordData;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceTestResult;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;

/**
 * Keywords a site is relevant for, with Google search volume (pay as you go).
 */
class DataForSeoKeywords implements KeywordSource
{
    /**
     * Google Ads location codes for common markets.
     */
    public const LOCATIONS = [
        'US' => 2840, 'GB' => 2826, 'CA' => 2124, 'AU' => 2036, 'IE' => 2372, 'NZ' => 2554, 'IN' => 2356,
        'DE' => 2276, 'FR' => 2250, 'ES' => 2724, 'IT' => 2380, 'NL' => 2528, 'SE' => 2752, 'AE' => 2784,
        'PK' => 2586, 'SG' => 2702, 'ZA' => 2710, 'BR' => 2076, 'MX' => 2484,
    ];

    public function key(): string
    {
        return 'dataforseo';
    }

    public function label(): string
    {
        return 'DataForSEO';
    }

    public function description(): string
    {
        return 'Keywords the brand\'s website is relevant for, with Google search volumes. Pay-as-you-go pricing per request.';
    }

    public function formFields(): array
    {
        return [
            TextInput::make('credentials.login')->label('API login')->autocomplete('off'),
            TextInput::make('credentials.password')
                ->label('API password')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->helperText('From app.dataforseo.com/api-access. Leave empty to keep the saved password.'),
            TextInput::make('config.target')
                ->label('Website')
                ->placeholder('Defaults to the brand\'s main website'),
            TextInput::make('config.location_code')
                ->label('Location code')
                ->numeric()
                ->placeholder('Defaults from the brand\'s market (e.g. 2826 for the UK)'),
            TextInput::make('config.language_code')->label('Language code')->placeholder('en'),
        ];
    }

    public function test(Connection $connection): SourceTestResult
    {
        try {
            $response = $this->http($connection)->get('/appendix/user_data');
        } catch (ConnectionException) {
            return SourceTestResult::failed('Could not reach DataForSEO.');
        }

        $status = (int) $response->json('status_code');

        if ($response->status() === 401 || ($status >= 40100 && $status < 40200)) {
            return SourceTestResult::failed('DataForSEO rejected the login or password.', credentialsRejected: true);
        }

        if ($status !== 20000) {
            return SourceTestResult::failed('DataForSEO: ' . ($response->json('status_message') ?? 'HTTP ' . $response->status()));
        }

        $balance = $response->json('tasks.0.result.0.money.balance');

        return SourceTestResult::ok($balance !== null ? 'Connected. Balance $' . number_format((float) $balance, 2) . '.' : 'Connected.');
    }

    public function fetch(Connection $connection): iterable
    {
        $target = $connection->setting('target') ?: $connection->brand?->primaryDomain();

        if (! $target) {
            throw new SourceFailed('Set the website to analyse.');
        }

        $country = $connection->brand?->countryCode();

        try {
            $response = $this->http($connection)->post('/keywords_data/google_ads/keywords_for_site/live', [array_filter([
                'target' => $target,
                'target_type' => 'site',
                'location_code' => (int) ($connection->setting('location_code') ?: (static::LOCATIONS[$country] ?? 2840)),
                'language_code' => $connection->setting('language_code') ?: 'en',
                'sort_by' => 'search_volume',
            ])]);
        } catch (ConnectionException $e) {
            throw new SourceFailed('Could not reach DataForSEO: ' . $e->getMessage());
        }

        $status = (int) ($response->json('tasks.0.status_code') ?? $response->json('status_code'));

        if ($response->status() === 401 || ($status >= 40100 && $status < 40200)) {
            throw new SourceFailed('DataForSEO rejected the login or password.', credentialsRejected: true);
        }

        if ($status !== 20000) {
            throw new SourceFailed('DataForSEO: ' . ($response->json('tasks.0.status_message') ?? $response->json('status_message') ?? 'HTTP ' . $response->status()));
        }

        foreach ((array) $response->json('tasks.0.result', []) as $item) {
            if (filled($item['keyword'] ?? null)) {
                yield new KeywordData(
                    keyword: $item['keyword'],
                    searchVolume: isset($item['search_volume']) ? (int) $item['search_volume'] : null,
                    metadata: array_filter(['competition' => $item['competition'] ?? null, 'cpc' => $item['cpc'] ?? null]),
                );
            }
        }
    }

    protected function http(Connection $connection): PendingRequest
    {
        return Http::timeout(120)
            ->baseUrl('https://api.dataforseo.com/v3')
            ->withBasicAuth((string) $connection->credential('login'), (string) $connection->credential('password'))
            ->acceptJson()
            ->asJson();
    }
}
