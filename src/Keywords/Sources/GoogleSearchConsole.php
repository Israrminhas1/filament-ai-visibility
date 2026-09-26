<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords\Sources;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordData;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceTestResult;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;

/**
 * Real Google queries for the brand's site, with clicks and impressions.
 * Connects with a Google Cloud service account that has been added as a
 * user on the Search Console property.
 */
class GoogleSearchConsole implements KeywordSource
{
    protected const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function key(): string
    {
        return 'gsc';
    }

    public function label(): string
    {
        return 'Google Search Console';
    }

    public function description(): string
    {
        return 'Real Google queries for your site with clicks and impressions (last 90 days). Free; needs a Google Cloud service account added to the property.';
    }

    public function formFields(): array
    {
        return [
            Textarea::make('credentials.service_account')
                ->label('Service account key (JSON)')
                ->rows(4)
                ->helperText('In Google Cloud: create a service account, enable the Search Console API, create a JSON key and paste it here. Then add the service account\'s email as a user of your Search Console property. Leave empty to keep the saved key.'),
            TextInput::make('config.property')
                ->label('Search Console property')
                ->placeholder('sc-domain:acme.com or https://www.acme.com/')
                ->required(),
            TextInput::make('config.min_impressions')
                ->label('Minimum impressions')
                ->numeric()
                ->default(10),
            TextInput::make('config.row_limit')
                ->label('Max queries')
                ->numeric()
                ->default(500)
                ->maxValue(5000),
        ];
    }

    public function test(Connection $connection): SourceTestResult
    {
        try {
            $token = $this->token($connection);
            $response = Http::timeout(30)->withToken($token)->get('https://www.googleapis.com/webmasters/v3/sites');
        } catch (SourceFailed $e) {
            return SourceTestResult::failed($e->getMessage(), $e->credentialsRejected);
        } catch (ConnectionException) {
            return SourceTestResult::failed('Could not reach Google.');
        }

        if ($response->failed()) {
            return SourceTestResult::failed('Search Console: ' . ($response->json('error.message') ?? 'HTTP ' . $response->status()), in_array($response->status(), [401, 403], true));
        }

        $property = (string) $connection->setting('property');
        $sites = collect($response->json('siteEntry', []))->pluck('siteUrl');

        if (! $sites->contains($property)) {
            return SourceTestResult::failed("The service account can't see \"{$property}\". Add its email as a user of that property in Search Console." . ($sites->isNotEmpty() ? ' It can see: ' . $sites->implode(', ') : ''));
        }

        return SourceTestResult::ok('Connected.');
    }

    public function fetch(Connection $connection): iterable
    {
        $token = $this->token($connection);
        $property = (string) $connection->setting('property');

        try {
            $response = Http::timeout(60)->withToken($token)->post(
                'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query',
                [
                    'startDate' => now()->subDays(90)->toDateString(),
                    'endDate' => now()->subDays(2)->toDateString(),
                    'dimensions' => ['query'],
                    'rowLimit' => min(5000, (int) $connection->setting('row_limit', 500)),
                ],
            );
        } catch (ConnectionException $e) {
            throw new SourceFailed('Could not reach Google: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new SourceFailed('Search Console: ' . ($response->json('error.message') ?? 'HTTP ' . $response->status()), in_array($response->status(), [401, 403], true));
        }

        $minImpressions = (int) $connection->setting('min_impressions', 10);

        foreach ((array) $response->json('rows', []) as $row) {
            $query = $row['keys'][0] ?? null;

            if (filled($query) && (int) ($row['impressions'] ?? 0) >= $minImpressions) {
                yield new KeywordData(
                    keyword: $query,
                    clicks: (int) ($row['clicks'] ?? 0),
                    impressions: (int) ($row['impressions'] ?? 0),
                    position: isset($row['position']) ? round((float) $row['position'], 2) : null,
                );
            }
        }
    }

    /**
     * An access token from the service account key (JWT bearer grant).
     */
    protected function token(Connection $connection): string
    {
        $key = json_decode((string) $connection->credential('service_account'), true);

        if (! is_array($key) || blank($key['client_email'] ?? null) || blank($key['private_key'] ?? null)) {
            throw new SourceFailed('The service account key is missing or is not valid JSON.', credentialsRejected: true);
        }

        $now = time();
        $segments = [
            $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64url(json_encode([
                'iss' => $key['client_email'],
                'scope' => static::SCOPE,
                'aud' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new SourceFailed('The service account private key could not be read.', credentialsRejected: true);
        }

        $segments[] = $this->base64url($signature);

        try {
            $response = Http::asForm()->timeout(30)->post($key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);
        } catch (ConnectionException) {
            throw new SourceFailed('Could not reach Google.');
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new SourceFailed('Google rejected the service account: ' . ($response->json('error_description') ?? $response->json('error') ?? 'HTTP ' . $response->status()), credentialsRejected: true);
        }

        return $response->json('access_token');
    }

    protected function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
