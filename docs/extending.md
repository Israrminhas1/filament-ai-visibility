# Extending

- [Where to register extensions](#where-to-register-extensions)
- [Adding a custom AI engine](#adding-a-custom-ai-engine)
  - [The Engine contract](#the-engine-contract)
  - [Example: an engine on HttpEngine](#example-an-engine-on-httpengine)
  - [Errors and pausing](#errors-and-pausing)
  - [Config, keys and prices for the new engine](#config-keys-and-prices-for-the-new-engine)
  - [Batch support (economy mode)](#batch-support-economy-mode)
  - [Removing or replacing a built-in engine](#removing-or-replacing-a-built-in-engine)
- [Adding a custom keyword source](#adding-a-custom-keyword-source)
- [Events](#events)
- [Customising services through the container](#customising-services-through-the-container)
- [Customising AI instructions](#customising-ai-instructions)
- [Views](#views)
- [Translations](#translations)

## Where to register extensions

Engines and keyword sources live in two registries, both container singletons:

- `IsrarMinhas\FilamentAiVisibility\Engines\EngineRegistry`
- `IsrarMinhas\FilamentAiVisibility\Keywords\KeywordSourceRegistry`

Register extensions in a service provider with the plugin's static methods. A service provider runs in every process: web requests, queue workers, scheduled commands and Artisan. That matters because answers are fetched by queue jobs, not in the panel.

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\AiVisibility\AcmeEngine;
use App\AiVisibility\UrlCsvKeywords;
use Illuminate\Support\ServiceProvider;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        AiVisibilityPlugin::registerEngine(AcmeEngine::class);
        AiVisibilityPlugin::removeEngine('grok');
        AiVisibilityPlugin::registerKeywordSource(UrlCsvKeywords::class);
    }
}
```

The fluent panel options `->engine()`, `->withoutEngine()` and `->keywordSource()` do the same thing, but they only take effect in processes that build your panel. Filament builds panels while loading routes, so with `php artisan route:cache` a queue worker may never see them. Use the static methods for anything jobs need. Registering the same key twice just replaces the entry.

## Adding a custom AI engine

### The Engine contract

`IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine`:

| Method | Returns | Purpose |
|---|---|---|
| `key(): string` | e.g. `'acme'` | Unique key. Stored on answers, settings and engine states. Do not change it later. |
| `label(): string` | e.g. `'Acme AI'` | Name shown in the panel. |
| `defaultTrackingModel(): string` | model name | Model for tracked prompts unless Settings or the brand picks another. |
| `defaultHelperModel(): string` | model name | Cheaper model for helper work in "Automatic" mode. |
| `suggestedModels(): array` | `string[]` | Model picker suggestions. Users may type any model. |
| `aiMonitorProviders(): array` | `string[]` | Names the key may be stored under in AI Monitor. |
| `credentialKey(): string` | e.g. `'acme'` | Name the key is stored under (panel, `config('ai-visibility.keys.*')`). Engines that share a provider return the same value and share one key. |
| `supportsCompletion(): bool` | bool | Whether the engine can do helper calls. Search-only engines return `false`. |
| `testKey(string $apiKey): KeyTestResult` | result | A cheap request that proves the key works. Used by **Test key**, the setup wizard, **Test & resume** and the automatic probe. |
| `ask(EngineRequest $request): EngineResponse` | answer | Answer a tracked prompt **with web search**, returning the answer and its sources. Throw `EngineRequestFailed` on failure. |
| `complete(CompletionRequest $request): CompletionResponse` | text | A plain completion without web search for helper features. Throw `EngineRequestFailed` on failure. |

Value objects (all in `IsrarMinhas\FilamentAiVisibility\Engines`):

- `EngineRequest`: `prompt`, `model`, `apiKey`, `country` (two-letter code or `null`).
- `EngineResponse`: `answer`, `citations` (list of `['url' => string, 'title' => ?string]`, in the engine's order), `model`, `inputTokens`, `outputTokens`, `searches`. `EngineResponse::uniqueCitations($items)` turns a list of arrays (`url` or `uri` key) or URL strings into de-duplicated `http(s)` citations.
- `CompletionRequest`: `prompt`, `model`, `apiKey`, `json` (the caller expects JSON), `maxTokens`, `system`.
- `CompletionResponse`: `text`, `model`, `inputTokens`, `outputTokens`, `searches`.
- `KeyTestResult::ok(string $message = 'Connected', array $models = [])` or `KeyTestResult::failed(PauseReason $reason, ?string $detail = null)`.

### Example: an engine on HttpEngine

`IsrarMinhas\FilamentAiVisibility\Engines\Drivers\HttpEngine` implements most of the contract for HTTP APIs: key tests, error classification, pausing reasons and JSON handling. You provide the requests and the parsing.

This example is for a fictional "Acme" chat API with a web-search option. Replace the URLs and JSON paths with your provider's.

```php
<?php

namespace App\AiVisibility;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\CompletionResponse;
use IsrarMinhas\FilamentAiVisibility\Engines\Drivers\HttpEngine;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequest;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed;
use IsrarMinhas\FilamentAiVisibility\Engines\EngineResponse;
use IsrarMinhas\FilamentAiVisibility\Enums\PauseReason;

class AcmeEngine extends HttpEngine
{
    public function key(): string
    {
        return 'acme';
    }

    public function label(): string
    {
        return 'Acme AI';
    }

    // HttpEngine reads these from config('ai-visibility.engines.acme.*');
    // overriding them means the engine also works without a config entry.
    public function defaultTrackingModel(): string
    {
        return config('ai-visibility.engines.acme.tracking_model', 'acme-large');
    }

    public function defaultHelperModel(): string
    {
        return config('ai-visibility.engines.acme.helper_model', 'acme-small');
    }

    public function suggestedModels(): array
    {
        return config('ai-visibility.engines.acme.models', ['acme-large', 'acme-small']);
    }

    /**
     * An HTTP client with the key. $this->client() applies the configured timeout and JSON headers.
     */
    protected function http(string $apiKey): PendingRequest
    {
        return $this->client()
            ->baseUrl('https://api.acme.example/v1')
            ->withToken($apiKey);
    }

    /**
     * The cheapest request that proves the key works.
     */
    protected function sendKeyTest(PendingRequest $request): Response
    {
        return $request->get('/models');
    }

    /**
     * A tracked prompt, with web search on.
     */
    protected function sendAsk(PendingRequest $http, EngineRequest $request): Response
    {
        $search = ['enabled' => true, 'max_results' => (int) config('ai-visibility.tracking.max_searches', 5)];

        if ($request->country) {
            $search['country'] = $request->country;
        }

        return $http->post('/chat', [
            'model' => $request->model,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
            'web_search' => $search,
            'max_tokens' => (int) config('ai-visibility.tracking.max_output_tokens', 4096),
        ]);
    }

    protected function parseAnswer(Response $response, EngineRequest $request): EngineResponse
    {
        // A 200 response can still carry an error for some providers.
        if ($response->json('error.type') === 'search_unavailable') {
            throw new EngineRequestFailed('Acme AI: web search is not available for this model.', PauseReason::ModelUnavailable);
        }

        return new EngineResponse(
            answer: trim((string) $response->json('choices.0.message.content')),
            citations: EngineResponse::uniqueCitations($response->json('citations', [])),
            model: $response->json('model') ?? $request->model,
            inputTokens: $response->json('usage.prompt_tokens'),
            outputTokens: $response->json('usage.completion_tokens'),
            searches: (int) $response->json('usage.web_searches', 0),
        );
    }

    /**
     * A plain helper request, without web search.
     */
    protected function sendCompletion(PendingRequest $http, CompletionRequest $request): Response
    {
        $messages = [];

        if ($request->system) {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }

        // jsonInstruction() adds "Respond with valid JSON only…" when JSON is expected.
        $messages[] = ['role' => 'user', 'content' => $request->prompt . $this->jsonInstruction($request)];

        return $http->post('/chat', [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
        ]);
    }

    protected function parseCompletion(Response $response, CompletionRequest $request): CompletionResponse
    {
        return new CompletionResponse(
            text: (string) $response->json('choices.0.message.content'),
            model: $response->json('model') ?? $request->model,
            inputTokens: $response->json('usage.prompt_tokens'),
            outputTokens: $response->json('usage.completion_tokens'),
        );
    }
}
```

`HttpEngine` also gives you:

| Method | Default | Override when |
|---|---|---|
| `aiMonitorProviders()` | `[key()]` | AI Monitor stores the key under other names. |
| `credentialKey()` | `key()` | Several engines share one key. |
| `supportsCompletion()` | `true` | The engine cannot do plain completions. |
| `modelsFromKeyTest(Response)` | `data.*.id` of the key-test response | The model list is elsewhere. |
| `errorMessage(Response)` | `error.message`, `message`… | The provider puts the message elsewhere. |
| `ask()` / `complete()` | one request each | You need several requests per answer (the Anthropic driver continues `pause_turn` answers this way). |

To write an engine without `HttpEngine`, implement the contract directly. A search-only engine returns `false` from `supportsCompletion()` and throws `EngineRequestFailed` from `complete()`. See `SerpApiGoogleEngine` in the package for an example.

### Errors and pausing

Throw `IsrarMinhas\FilamentAiVisibility\Engines\EngineRequestFailed` from `ask()`, `complete()` and batch methods. Its `reason` (an `IsrarMinhas\FilamentAiVisibility\Enums\PauseReason` or `null`) decides what happens:

| Reason | What happens to the engine | What happens to the answer |
|---|---|---|
| `InvalidKey`, `MissingKey`, `ModelUnavailable` | Pauses until someone fixes it and clicks **Test & resume**. | Skipped. |
| `InsufficientCredits` | Pauses; re-tested every `reliability.credits_probe_minutes`. | Skipped. |
| `RateLimited` | Slowed to half speed; pauses after `reliability.rate_limit_threshold` in a row. | Retried later (after `retryAfter` seconds, at least 30). |
| `ProviderOutage` | Pauses after `reliability.failure_threshold` in a row, with growing pauses. | Retried with back-off (30 s, 2, 5, 10 min). |
| `null` | Nothing. | Fails (only this answer). |

With `HttpEngine`, failed HTTP responses are classified for you: 401/403 → invalid key (or credits if the body talks about billing), 402 → credits, 429 → rate limited (or credits), 404 → model unavailable, 400 with key/billing/model wording → the matching reason, 5xx and connection errors → outage. Provider error codes such as `insufficient_quota`, `rate_limit_exceeded`, `invalid_api_key` and Google's `RESOURCE_EXHAUSTED` are recognised. A numeric `Retry-After` header is passed on.

An empty answer counts as a failed answer.

### Config, keys and prices for the new engine

Add these to your published `config/ai-visibility.php`. None is strictly required, but without them the engine has no environment key, no price and a generic estimate.

```php
'keys' => [
    // ...
    'acme' => env('AI_VISIBILITY_ACME_KEY'),        // key: the engine's credentialKey()
],

'engines' => [
    // ...
    'acme' => [
        'tracking_model' => 'acme-large',
        'helper_model' => 'acme-small',
        'models' => ['acme-large', 'acme-small'],
    ],
],

'estimated_cost_per_result' => [
    // ...
    'acme' => 0.02,
],

'pricing' => [
    'models' => [
        // ...
        'acme-large' => [2.00, 8.00],   // USD per 1M input / output tokens
        'acme-small' => [0.20, 0.80],
    ],
    'fallback' => [
        // ...
        'acme' => [2.00, 8.00],
    ],
    'search_fee' => [
        // ...
        'acme' => 0.01,                 // USD per search
    ],
    // ...
],
```

The engine appears in Settings, the setup wizard, the brand's engine list, Health and `ai-visibility:engines`. For helper work in "Automatic" mode, engines not in `helper_engine_order` are tried after the listed ones; add `'acme'` to the list to change its place.

### Batch support (economy mode)

Implement `IsrarMinhas\FilamentAiVisibility\Engines\Contracts\SupportsBatches` to let scheduled runs use your provider's batch API when economy mode is on:

| Method | Must |
|---|---|
| `submitBatch(string $apiKey, array $requests): string` | Send every `EngineRequest` (keyed by a custom ID such as `result-123`) and return the provider's batch ID. Throw `EngineRequestFailed` if the batch was not accepted. |
| `batchStatus(string $apiKey, string $batchId): BatchStatus` | Return `new BatchStatus(BatchStatus::PENDING)`, `BatchStatus::DONE` (also for an expired batch that has partial results) or `BatchStatus::FAILED` with a message. |
| `batchResults(string $apiKey, string $batchId): iterable` | Yield `customId => EngineResponse` for answers, and `customId => EngineRequestFailed` for items that failed. |

Added to the `AcmeEngine` class above (keep its existing imports):

```php
use IsrarMinhas\FilamentAiVisibility\Engines\BatchStatus;
use IsrarMinhas\FilamentAiVisibility\Engines\Contracts\SupportsBatches;

class AcmeEngine extends HttpEngine implements SupportsBatches
{
    // ... everything from the example above ...

    public function submitBatch(string $apiKey, array $requests): string
    {
        $items = [];

        foreach ($requests as $customId => $request) {
            $items[] = [
                'custom_id' => (string) $customId,
                'model' => $request->model,
                'messages' => [['role' => 'user', 'content' => $request->prompt]],
                'web_search' => ['enabled' => true],
            ];
        }

        return (string) $this->send(fn () => $this->http($apiKey)->post('/batches', ['requests' => $items]))->json('id');
    }

    public function batchStatus(string $apiKey, string $batchId): BatchStatus
    {
        $batch = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/batches/{$batchId}"));

        return match ($batch->json('status')) {
            'completed', 'expired' => new BatchStatus(BatchStatus::DONE),
            'failed', 'cancelled' => new BatchStatus(BatchStatus::FAILED, $batch->json('error.message')),
            default => new BatchStatus(BatchStatus::PENDING),
        };
    }

    public function batchResults(string $apiKey, string $batchId): iterable
    {
        $results = $this->sendBatchRequest(fn () => $this->http($apiKey)->get("/batches/{$batchId}/results"));

        foreach ((array) $results->json('results', []) as $item) {
            $id = (string) ($item['custom_id'] ?? '');

            yield $id => isset($item['response'])
                ? new EngineResponse(
                    answer: trim((string) data_get($item, 'response.choices.0.message.content')),
                    citations: EngineResponse::uniqueCitations(data_get($item, 'response.citations', [])),
                    model: (string) data_get($item, 'response.model', ''),
                    inputTokens: data_get($item, 'response.usage.prompt_tokens'),
                    outputTokens: data_get($item, 'response.usage.completion_tokens'),
                    searches: (int) data_get($item, 'response.usage.web_searches', 0),
                )
                : new EngineRequestFailed('Acme AI: ' . (data_get($item, 'error.message') ?? 'the batch request failed.'));
        }
    }
}
```

`sendBatchRequest()` treats a 404 as "batch gone" rather than an engine problem. The package handles everything else: recording the batch before submitting, never sending the same answers twice, polling every 5 minutes, retrying in real time what the batch did not answer, and applying `pricing.batch_discount`.

### Removing or replacing a built-in engine

```php
// In a service provider's register() method:
AiVisibilityPlugin::removeEngine('grok');                         // remove
AiVisibilityPlugin::registerEngine(\App\AiVisibility\MyOpenAi::class); // same key 'openai' replaces the built-in
```

Removing an engine does not delete its past answers; reports still show them.

## Adding a custom keyword source

Implement `IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource`:

| Method | Purpose |
|---|---|
| `key(): string` | Stored as the connection type. Unique. |
| `label(): string` | Name in the source picker. |
| `description(): string` | One line shown when choosing the source. |
| `formFields(): array` | Filament form fields. Put secrets under `credentials.*` (stored encrypted, never shown again, "leave empty to keep") and options under `config.*`. |
| `test(Connection $connection): SourceTestResult` | Check the credentials. `SourceTestResult::ok($message)` or `SourceTestResult::failed($message, credentialsRejected: true)`. |
| `fetch(Connection $connection): iterable` | Yield `KeywordData` objects. Throw `SourceFailed` for problems the user should see. |

On a `Connection`, read values with `$connection->credential('token')` and `$connection->setting('url')`. `$connection->brand` is the brand.

`KeywordData` fields: `keyword` (required), `searchVolume`, `clicks`, `impressions`, `position`, `intent`, `metadata` (array).

Complete example: keywords from a CSV file at a URL.

```php
<?php

namespace App\AiVisibility;

use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Keywords\KeywordData;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceFailed;
use IsrarMinhas\FilamentAiVisibility\Keywords\SourceTestResult;
use IsrarMinhas\FilamentAiVisibility\Models\Connection;

class UrlCsvKeywords implements KeywordSource
{
    public function key(): string
    {
        return 'url_csv';
    }

    public function label(): string
    {
        return 'CSV from a URL';
    }

    public function description(): string
    {
        return 'Downloads a CSV with "keyword" and optional "search_volume" columns on every sync.';
    }

    public function formFields(): array
    {
        return [
            TextInput::make('config.url')
                ->label('CSV URL')
                ->url()
                ->required(),
            TextInput::make('credentials.token')
                ->label('Bearer token (optional)')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->helperText('Leave empty to keep the saved token.'),
        ];
    }

    public function test(Connection $connection): SourceTestResult
    {
        try {
            $response = $this->request($connection);
        } catch (ConnectionException) {
            return SourceTestResult::failed('Could not reach the URL.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return SourceTestResult::failed('The server rejected the token.', credentialsRejected: true);
        }

        return $response->successful()
            ? SourceTestResult::ok('Connected.')
            : SourceTestResult::failed('HTTP ' . $response->status());
    }

    public function fetch(Connection $connection): iterable
    {
        try {
            $response = $this->request($connection);
        } catch (ConnectionException $e) {
            throw new SourceFailed('Could not reach the URL.', previous: $e);
        }

        if ($response->failed()) {
            throw new SourceFailed('Download failed: HTTP ' . $response->status(), credentialsRejected: in_array($response->status(), [401, 403], true));
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($response->body()));
        $header = array_map(fn ($value) => strtolower(trim($value)), str_getcsv((string) array_shift($lines)));
        $keywordColumn = array_search('keyword', $header, true);
        $volumeColumn = array_search('search_volume', $header, true);

        if ($keywordColumn === false) {
            throw new SourceFailed('The CSV has no "keyword" column.');
        }

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            $keyword = trim((string) ($row[$keywordColumn] ?? ''));

            if ($keyword === '') {
                continue;
            }

            $volume = $volumeColumn !== false && is_numeric($row[$volumeColumn] ?? null) ? (int) $row[$volumeColumn] : null;

            yield new KeywordData($keyword, searchVolume: $volume, metadata: ['source_url' => $connection->setting('url')]);
        }
    }

    protected function request(Connection $connection): \Illuminate\Http\Client\Response
    {
        $http = Http::timeout(30);

        if ($token = $connection->credential('token')) {
            $http = $http->withToken($token);
        }

        return $http->get((string) $connection->setting('url'));
    }
}
```

Register it as shown in [Where to register extensions](#where-to-register-extensions).

What the package does with it:

- New keywords are added up to the brand's keyword limit; existing keywords get fresh metrics.
- Keywords from custom sources are stored with the source "Connected source" (`custom`).
- Syncs run on **Sync now** and every `keywords.sync_days` (daily check at 05:00).
- A `SourceFailed` marks the connection **Error** (or **Needs new credentials** when `credentialsRejected` is true), stores the message, sends one alert, and retries the next day. Secrets are removed from the message. Any other exception is logged and shown as a generic failure.

## Events

All events are in `IsrarMinhas\FilamentAiVisibility\Events` and are dispatched synchronously.

| Event | Payload | Fired when |
|---|---|---|
| `RunStarted` | `Run $run` | A run was created and its jobs queued (Run now, schedule, `ai-visibility:run`). |
| `ResultRecorded` | `Result $result` | An answer was stored successfully (real-time job, or economy batch collected by `poll-batches`). Not fired for failed or skipped answers. |
| `RunCompleted` | `Run $run` | A run closed, once, whatever its status (`completed`, `partial`, `failed`, `stopped_budget`, `stopped_paused`), including runs closed by the sweeper. The package itself queues discovery and alert checks on this event. |
| `EnginePaused` | `string $engine`, `PauseReason $reason`, `?string $message`, `int\|string\|null $tenantId` | An engine paused. Once per pause episode, not per failed request. |
| `EngineResumed` | `string $engine`, `int\|string\|null $tenantId` | A paused engine was resumed (automatically or by a person). |
| `CandidateClassified` | `Candidate $candidate` | A discovered candidate got a label from the AI classifier. |
| `CompetitorAccepted` | `Candidate $candidate`, `Competitor $competitor` | A candidate was tracked as a new competitor (by a user, or by auto-accept). |

`Run` and `Result` carry `tenant_id` and `brand_id`. Useful `Result` columns: `engine`, `model`, `status`, `brand_mentioned`, `brand_cited`, `brand_position`, `brand_mention_count`, `cost_usd`, `answer`, and relations `brand`, `prompt`, `run`.

Example listener: post to your own webhook when a run finishes.

```php
// app/Providers/AppServiceProvider.php, boot()
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use IsrarMinhas\FilamentAiVisibility\Events\EnginePaused;
use IsrarMinhas\FilamentAiVisibility\Events\RunCompleted;

Event::listen(RunCompleted::class, function (RunCompleted $event) {
    $run = $event->run;

    Http::post(config('services.reporting.webhook'), [
        'tenant' => $run->tenant_id,
        'brand_id' => $run->brand_id,
        'status' => $run->status->value,
        'answers' => $run->results_done,
        'failed' => $run->results_failed,
        'skipped' => $run->results_skipped,
    ]);
});

Event::listen(EnginePaused::class, function (EnginePaused $event) {
    logger()->warning("AI Visibility engine {$event->engine} paused: {$event->reason->getLabel()}", [
        'tenant' => $event->tenantId,
        'message' => $event->message,
    ]);
});
```

Listeners run inside the job or request that fired the event, in that tenant's context. A queued listener (`ShouldQueue`) loses that context; run your queries as the tenant:

```php
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

public function handle(RunCompleted $event): void
{
    Tenancy::as($event->run->tenant_id, function () use ($event) {
        // queries here are scoped to the run's tenant
    });
}
```

Keep synchronous listeners fast: `ResultRecorded` fires once per answer inside the answer job.

## Customising services through the container

These services are registered as singletons, so one binding replaces them everywhere (web, jobs and commands):

| Class | Role |
|---|---|
| `Engines\EngineRegistry` | Engines. |
| `Keywords\KeywordSourceRegistry` | Keyword sources. |
| `Engines\EngineManager` | Enabled/usable engines, pausing, resuming, helper engine choice. |
| `Engines\KeyResolver` | Finds API keys (panel, AI Monitor, env). |
| `Support\Settings` | Tenant settings. |
| `Support\Limits` | Brand, prompt, competitor, keyword and run limits. |
| `Support\Importer` | CSV and paste imports. |
| `Support\Health\SystemHealth` | Health checks. |
| `Support\Alerts\AlertNotifier` | Alert delivery (inbox, panel, email, Slack). |
| `Support\Pricing` | Cost of a call. |
| `Support\Spend` | Usage records and monthly spend. |
| `Support\Instructions` | AI instructions. |
| `Support\HelperAi` | Helper calls. |
| `Runs\RunPlanner` | Starting runs. |
| `Runs\RunProgress` | Answer claims and run completion. |
| `Runs\BudgetGuard` | Budget checks and pauses. |
| `Reports\Metrics` | Report metrics. |

All class names are under `IsrarMinhas\FilamentAiVisibility\`. Other services (such as `Runs\Economy`, `Runs\ResultRecorder`, `Competitors\Classifier`, `Reports\ReportSender`) are resolved from the container too, so `bind()` works for them.

Bind your subclass in `register()` of a service provider. App providers register after package providers, so your binding wins.

Example: add a flat per-answer platform fee to every cost.

```php
namespace App\AiVisibility;

use IsrarMinhas\FilamentAiVisibility\Support\Pricing;

class PricingWithFee extends Pricing
{
    public function cost(string $engine, ?string $model, ?int $inputTokens, ?int $outputTokens, int $searches = 0, bool $batch = false): float
    {
        return parent::cost($engine, $model, $inputTokens, $outputTokens, $searches, $batch) + 0.001;
    }
}
```

```php
// AppServiceProvider::register()
$this->app->singleton(\IsrarMinhas\FilamentAiVisibility\Support\Pricing::class, \App\AiVisibility\PricingWithFee::class);
```

Example: send every alert to an extra channel.

```php
namespace App\AiVisibility;

use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;

class AlertNotifierWithPager extends AlertNotifier
{
    public function send(Alert $alert, ?array $channels = null): void
    {
        parent::send($alert, $channels);

        if ($alert->level === 'danger') {
            // your own channel, e.g. a paging service
            logger()->critical("[AI Visibility] {$alert->title}", ['body' => $alert->body, 'url' => $alert->url]);
        }
    }
}
```

```php
$this->app->singleton(\IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier::class, \App\AiVisibility\AlertNotifierWithPager::class);
```

The contracts, events, registries and value objects are the stable extension points. Other classes are internal and their methods can change between versions; check the changelog when you upgrade.

## Customising AI instructions

AI Visibility sends seven instructions to the helper AI:

| Key | Used for | Placeholders |
|---|---|---|
| `generation` | Prompt generation | `{brand}`, `{domain}`, `{description}`, `{industry}`, `{market}`, `{persona}`, `{competitors}`, `{keywords}`, `{count}`, `{topic}`, `{intents}`, `{existing_prompts}` |
| `quality_review` | Scoring generated prompts 1–5 | `{brand}`, `{description}`, `{market}`, `{prompts}` |
| `topics` | Grouping prompts into topics | `{brand}`, `{description}`, `{prompts}` |
| `analysis` | Sentiment, recommendation and descriptors per mention; other company names | `{brand}`, `{competitors}`, `{answers}` |
| `classification` | Labelling competitor candidates | `{brand}`, `{domain}`, `{description}`, `{industry}`, `{market}`, `{competitors}`, `{labels}`, `{examples}`, `{candidates}` |
| `extraction` | Company and product names in answers | `{brand}`, `{answers}` |
| `suggest_competitors` | Competitor suggestions in the setup wizard | `{brand}`, `{domain}`, `{description}`, `{industry}`, `{market}`, `{existing}`, `{count}` |

Each instruction ends by asking for a specific JSON shape. Keep that shape: the code reads those fields.

**Per tenant, in the panel:** Settings → AI instructions. Empty = built-in (shown greyed out).

**In code, per tenant:**

```php
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

Tenancy::as($tenantId, fn () => app(Settings::class)->set([
    'instructions.extraction' => "...your text with {brand} and {answers}...",
]));
```

**For everyone, as the new built-in:** override `Instructions::default()`. This also covers tenants who saved the Settings page with the field empty.

```php
namespace App\AiVisibility;

use IsrarMinhas\FilamentAiVisibility\Support\Instructions;

class MyInstructions extends Instructions
{
    public static function default(string $key): string
    {
        return match ($key) {
            self::GENERATION => str_replace(
                'Write like a real person:',
                'Write in British English, like a real person:',
                parent::default($key),
            ),
            default => parent::default($key),
        };
    }
}
```

```php
// AppServiceProvider::register()
$this->app->singleton(\IsrarMinhas\FilamentAiVisibility\Support\Instructions::class, \App\AiVisibility\MyInstructions::class);
```

The Settings page still shows the package's original text as the greyed-out placeholder.

The tracked prompts themselves are sent to engines exactly as written, with no instruction added.

## Views

Publish the Blade views to change the HTML of report widgets, the Health page, the answer view, the setup screens and the email report:

```bash
php artisan vendor:publish --tag=ai-visibility-views
```

They are copied to `resources/views/vendor/ai-visibility`. Laravel uses your copy when it exists; delete a file to go back to the package version.

| View | What it renders |
|---|---|
| `mail/report.blade.php` | The scheduled email report (and its PDF copy). |
| `pages/health.blade.php` | The Health page. |
| `results/answer.blade.php`, `mentions.blade.php`, `sources.blade.php`, `legend.blade.php` | The answer page parts. |
| `prompts/history.blade.php` | Prompt history. |
| `candidates/evidence.blade.php` | Evidence for a discovered candidate. |
| `setup/*.blade.php` | Setup wizard parts (system checks, review, styles). |
| `widgets/*.blade.php` | Report widgets (leaderboard, heatmap, head-to-head, opportunities, top sources…). |
| `reports/empty.blade.php` | Empty report state. |

Publish only if you need to. Published views do not get fixes from package updates; compare them after upgrading.

## Translations

The interface text is English and written directly in the code. The package registers a translation namespace (`ai-visibility`) and a publish tag (`ai-visibility-translations`), but the language file is empty in this version, so there is nothing to translate yet.
