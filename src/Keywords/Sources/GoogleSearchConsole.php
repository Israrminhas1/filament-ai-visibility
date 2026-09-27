<?php

namespace IsrarMinhas\FilamentAiVisibility\Keywords\Sources;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Detection\Domains;
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
                ->placeholder('Leave empty to use the brand\'s domain')
                ->helperText('Found automatically from the brand\'s domain (e.g. sc-domain:acme.com or https://www.acme.com/). Fill it in only to use a different property.'),
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

        $sites = collect($response->json('siteEntry', []))->pluck('siteUrl')->all();

        try {
            $property = $this->resolveProperty($connection, $sites);
        } catch (SourceFailed $e) {
            return SourceTestResult::failed($e->getMessage());
        }

        return SourceTestResult::ok("Connected. Using {$property}.");
    }

    /**
     * The property to read: the one set on the connection, or the site in
     * Search Console that matches the brand's domain.
     *
     * @param  array<string>  $sites  Properties the service account can see.
     *
     * @throws SourceFailed
     */
    public function resolveProperty(Connection $connection, array $sites): string
    {
        $configured = trim((string) $connection->setting('property'));
        $email = $this->serviceAccountEmail($connection);
        $visible = $sites === [] ? ' It can\'t see any property yet.' : ' It can see: ' . implode(', ', $sites) . '.';

        if ($configured !== '') {
            if (in_array($configured, $sites, true)) {
                return $configured;
            }

            throw new SourceFailed("The service account can't see \"{$configured}\". In Search Console, add {$email} as a user of that property.{$visible}");
        }

        $domains = array_values(array_filter(array_map(
            fn ($domain) => Domains::host((string) $domain),
            (array) ($connection->brand?->domains ?? []),
        )));

        if ($domains === []) {
            throw new SourceFailed('Add the brand\'s website domain, or enter the Search Console property on this keyword source.');
        }

        if ($property = static::matchProperty($domains, $sites)) {
            return $property;
        }

        throw new SourceFailed('No Search Console property for ' . implode(', ', $domains) . ". In Search Console, open that site, go to Settings, then Users and permissions, then Add user, and add {$email} (Restricted is enough).{$visible}");
    }

    /**
     * The Search Console property for a brand's domains: a domain property
     * ("sc-domain:acme.com") first, then a URL-prefix property for the same
     * site, preferring https and the site root.
     *
     * @param  array<string>  $domains  Hosts without "www.", e.g. "acme.com".
     * @param  array<string>  $sites
     */
    public static function matchProperty(array $domains, array $sites): ?string
    {
        $wanted = collect($domains)->flatMap(fn ($domain) => [$domain, Domains::registrable($domain)])->filter()->unique()->values();

        foreach ($wanted as $domain) {
            if (in_array("sc-domain:{$domain}", $sites, true)) {
                return "sc-domain:{$domain}";
            }
        }

        return collect($sites)
            ->reject(fn ($site) => str_starts_with($site, 'sc-domain:'))
            ->filter(fn ($site) => $wanted->contains(Domains::host($site)))
            ->sortBy(fn ($site) => (str_starts_with($site, 'https://') ? 0 : 1000) + strlen((string) parse_url($site, PHP_URL_PATH)))
            ->first();
    }

    protected function serviceAccountEmail(Connection $connection): string
    {
        $key = json_decode((string) $connection->credential('service_account'), true);

        return is_array($key) && filled($key['client_email'] ?? null) ? $key['client_email'] : 'the service account\'s email';
    }

    /**
     * @return array<string>
     *
     * @throws SourceFailed
     */
    protected function sites(string $token): array
    {
        try {
            $response = Http::timeout(30)->withToken($token)->get('https://www.googleapis.com/webmasters/v3/sites');
        } catch (ConnectionException) {
            throw new SourceFailed('Could not reach Google.');
        }

        if ($response->failed()) {
            throw new SourceFailed('Search Console: ' . ($response->json('error.message') ?? 'HTTP ' . $response->status()), in_array($response->status(), [401, 403], true));
        }

        return collect($response->json('siteEntry', []))->pluck('siteUrl')->all();
    }

    public function fetch(Connection $connection): iterable
    {
        $token = $this->token($connection);
        $property = $this->resolveProperty($connection, $this->sites($token));

        // Remember the property that was found, so it shows on the keyword source.
        if (trim((string) $connection->setting('property')) === '' && $connection->exists) {
            $connection->forceFill(['config' => [...(array) $connection->config, 'property' => $property]])->saveQuietly();
        }

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
