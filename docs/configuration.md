# Configuration

AI Visibility has two layers of configuration:

1. **`config/ai-visibility.php`**: set by developers. Technical settings, prices, models, and the **defaults** for the Settings page.
2. **The Settings page** (stored in the database, per tenant): set by users who can manage settings. Engines, keys, schedules, limits, budgets, alerts and AI instructions.

Brands can then override some settings again ([per-brand overrides](#per-brand-overrides)).

- [Environment variables](#environment-variables)
- [config/ai-visibility.php](#configai-visibilityphp)
  - [Database and setup](#database-and-setup)
  - [Queues](#queues)
  - [API keys from the environment](#api-keys-from-the-environment)
  - [Engines and models](#engines-and-models)
  - [Helper engine order](#helper-engine-order)
  - [Cost estimates and pricing](#cost-estimates-and-pricing)
  - [Economy mode](#economy-mode)
  - [Markets](#markets)
  - [Source categories](#source-categories)
  - [Competitor discovery](#competitor-discovery)
  - [Tracking requests](#tracking-requests)
  - [Alerts, import limits, reliability](#alerts-import-limits-reliability)
  - [Health thresholds and HTTP](#health-thresholds-and-http)
- [Default settings (`defaults`)](#default-settings-defaults)
- [The Settings page](#the-settings-page)
- [Per-brand overrides](#per-brand-overrides)

## Environment variables

| Variable | Used for | Default |
|---|---|---|
| `AI_VISIBILITY_QUEUE_CONNECTION` | Queue connection for all jobs | App default |
| `AI_VISIBILITY_QUEUE` | Queue name for all jobs (fallback for the three below) | `default` |
| `AI_VISIBILITY_QUEUE_TRACKING` | Queue for answer jobs and batch submissions | `AI_VISIBILITY_QUEUE` |
| `AI_VISIBILITY_QUEUE_ANALYSIS` | Queue for alert checks | `AI_VISIBILITY_QUEUE` |
| `AI_VISIBILITY_QUEUE_CLASSIFICATION` | Queue for discovery, classification and re-detection | `AI_VISIBILITY_QUEUE` |
| `AI_VISIBILITY_OPENAI_KEY` | OpenAI key fallback | none |
| `AI_VISIBILITY_ANTHROPIC_KEY` | Anthropic key fallback | none |
| `AI_VISIBILITY_GEMINI_KEY` | Google Gemini key fallback | none |
| `AI_VISIBILITY_GROK_KEY` | xAI key fallback | none |
| `AI_VISIBILITY_PERPLEXITY_KEY` | Perplexity key fallback | none |
| `AI_VISIBILITY_SERPAPI_KEY` | SerpAPI key fallback (both Google engines) | none |

Also set your app's queue `retry_after` (for example `DB_QUEUE_RETRY_AFTER=960` or `REDIS_QUEUE_RETRY_AFTER=960`). See [Queues and scheduler](queues-and-scheduler.md#retry_after-and-worker-timeout-required).

## config/ai-visibility.php

Publish it with `php artisan vendor:publish --tag=ai-visibility-config` (the install command does this).

### Database and setup

| Key | Default | Meaning | When to change |
|---|---|---|---|
| `table_prefix` | `ai_visibility_` | Prefix for every AI Visibility table. | Before the first migration, if the names clash with your tables. Changing it later means renaming the tables yourself. |
| `tenant_support` | `true` | Scope all data to the current tenant. See [Permissions and tenancy](permissions-and-tenancy.md). | Set `false` to ignore tenants completely (every query sees every row). |
| `require_setup` | `true` | Runs only start once the setup wizard is complete. | Set `false` when you configure everything in code and use `->withoutSetupWizard()`. |

### Queues

| Key | Default | Meaning |
|---|---|---|
| `queues.connection` | `AI_VISIBILITY_QUEUE_CONNECTION` or app default | Connection for all jobs. |
| `queues.tracking` | `AI_VISIBILITY_QUEUE_TRACKING` → `AI_VISIBILITY_QUEUE` → `default` | Answer jobs (`RunResultJob`, `SubmitBatchJob`). |
| `queues.analysis` | `AI_VISIBILITY_QUEUE_ANALYSIS` → `AI_VISIBILITY_QUEUE` → `default` | `EvaluateAlertsJob`. |
| `queues.classification` | `AI_VISIBILITY_QUEUE_CLASSIFICATION` → `AI_VISIBILITY_QUEUE` → `default` | `DiscoverCompetitorsJob` (includes answer analysis), `ClassifyCandidatesJob`, `RedetectBrandJob`. |

Full explanation and worker setups: [Queues and scheduler](queues-and-scheduler.md).

### API keys from the environment

| Key | Env var |
|---|---|
| `keys.openai` | `AI_VISIBILITY_OPENAI_KEY` |
| `keys.anthropic` | `AI_VISIBILITY_ANTHROPIC_KEY` |
| `keys.gemini` | `AI_VISIBILITY_GEMINI_KEY` |
| `keys.grok` | `AI_VISIBILITY_GROK_KEY` |
| `keys.perplexity` | `AI_VISIBILITY_PERPLEXITY_KEY` |
| `keys.serpapi` | `AI_VISIBILITY_SERPAPI_KEY` (Google AI Overviews and Google AI Mode) |

Keys are looked up in this order, per engine:

1. A key saved in the panel (**Settings → Engines & API keys**), for the current tenant.
2. [AI Monitor](https://github.com/Israrminhas1/filament-aimonitor), if installed and it has a key for that provider.
3. These config values.

The key is stored under the engine's *credential key*. Both Google engines use `serpapi`. A custom engine can add its own entry here (see [Extending](extending.md#adding-a-custom-ai-engine)).

In a multi-tenant install, AI Monitor and environment keys are shared by every tenant without its own saved key. See [Permissions and tenancy](permissions-and-tenancy.md#keys-and-billing-across-tenants).

### Engines and models

```php
'engines' => [
    'openai' => [
        'tracking_model' => 'gpt-6-luna',
        'helper_model' => 'gpt-6-luna',
        'models' => ['gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-5.5', 'gpt-5.4', 'gpt-5-mini'],
    ],
    // anthropic, gemini, grok, perplexity, google_ai_overview, google_ai_mode...
],
```

| Key | Meaning |
|---|---|
| `engines.{engine}.tracking_model` | Default model for tracked prompts. Users can pick another in Settings (or per brand). |
| `engines.{engine}.helper_model` | Model used when this engine does helper work (analysis, classification, generation) in "Automatic" mode. Usually a cheaper model. |
| `engines.{engine}.models` | Suggestions in the model picker. Users can also type any model name. List only models that support the engine's web search and return sources. |

Defaults: `gpt-6-luna`, `claude-sonnet-5`, `gemini-3.8-flash` (helper `gemini-3.5-flash-lite`), `grok-4.7` (helper `grok-4.3`), `perplexity/sonar`. The Google engines have no model choice. Model names change often; check each provider's model list. Details per engine: [Engines and costs](engines-and-costs.md).

### Helper engine order

| Key | Default | Meaning |
|---|---|---|
| `helper_engine_order` | `['openai', 'anthropic', 'gemini', 'grok', 'perplexity']` | When **Settings → AI helpers** is "Automatic", helper calls use the first engine in this order that has a key and is not paused. Engines not listed (custom engines) are tried after these. Search-only engines (the Google engines) are never used for helpers. |

The automatic helper does not require the engine to be switched on for tracking. A key is enough.

### Cost estimates and pricing

| Key | Meaning |
|---|---|
| `estimated_cost_per_result.{engine}` | Rough USD cost of one tracked answer. Used for estimates only until at least 5 real answers exist for that engine. |
| `pricing.models.{model}` | `[input, output]` USD per 1M tokens. Matched exactly, then by prefix (`gpt-5-mini-2025-08-07` uses `gpt-5-mini`). |
| `pricing.fallback.{engine}` | Token price for models not listed above. |
| `pricing.search_fee.{engine}` | USD per web search (per SerpAPI search for the Google engines). |
| `pricing.search_fee_models.{prefix}` | Search fee for models that differ from their engine (default: `gemini-2.5` → 0.035 per grounded prompt). Longest prefix wins. |
| `pricing.batch_discount` | Token discount for economy (batch) answers. Default `0.5`. Search fees are not discounted. |

When AI Monitor is installed and has a price for the model, its token price is used instead of `pricing.models`. How costs and estimates are calculated: [Engines and costs](engines-and-costs.md#how-costs-are-calculated).

### Economy mode

| Key | Default | Meaning |
|---|---|---|
| `economy.max_batch_size` | `1000` | Most answers in one provider batch. Larger runs are split into several batches. |
| `economy.give_up_after_hours` | `26` | A batch still unfinished after this long is closed, and its unanswered prompts are asked in real time. |

Economy mode itself is switched on in Settings. See [Engines and costs](engines-and-costs.md#economy-batch-mode).

### Markets

| Key | Meaning |
|---|---|
| `markets` | Maps a brand's **Market** text (lower case) to a two-letter country code, e.g. `'uk' => 'GB'`. A two-letter code typed as the market ("GB") works without an entry. |

The country is sent as the search location to engines that support it. Add your own names if users type them.

### Source categories

| Key | Meaning |
|---|---|
| `source_categories.{category}` | Domains per category for the Sources report: `review_comparison`, `forum_community`, `social`, `marketplace`, `wiki_reference`, `media_publisher`, `government_education`. Subdomains match automatically. Entries starting with a dot (`.gov`, `.edu`) match domain endings. |

The brand's own domains are always "own", competitors' are "competitor". Domains in the non-competitor categories are also kept out of competitor discovery. Add domains that matter in your market.

### Competitor discovery

| Key | Default | Meaning |
|---|---|---|
| `discovery.window_days` | `90` | Answers from this many days are used to find and score candidates. |
| `discovery.evidence_days` | `30` | Fetched website evidence is reused for this many days. |
| `discovery.classification_batch` | `10` | Candidates per classification request. |
| `discovery.extraction_batch` | `8` | Answers per name-extraction request. |
| `discovery.analysis_batch` | `5` | Answers per analysis request. |
| `discovery.analysis_attempts` | `3` | Failed attempts before an answer's analysis is marked failed. |
| `discovery.retry_failed_days` | `7` | Days before retrying a candidate the AI could not label. |
| `discovery.weights` | answers 0.35, prompts 0.25, engines 0.20, position 0.10, recency 0.10 | How the 0–100 candidate score is built. Weights are relative. |
| `discovery.platform_domains` | `null` | Platforms that are never competitors. `null` uses the built-in list (reddit.com, youtube.com, wikipedia.org, g2.com, capterra.com, trustpilot.com, forbes.com, medium.com, quora.com, linkedin.com, facebook.com, x.com, twitter.com, instagram.com, tiktok.com, amazon.com, github.com). An array replaces the list. |
| `discovery.ignored_domains` | search engines, Google redirect hosts, link shorteners, archive.org | Never candidates, for every tenant. Users can add more in Settings. |

### Tracking requests

| Key | Default | Meaning |
|---|---|---|
| `tracking.max_output_tokens` | `4096` | Answer length limit sent to engines that need one (Anthropic, Perplexity). |
| `tracking.max_searches` | `5` | Most web searches per answer, where the engine supports a limit (Anthropic). |
| `tracking.retry_for_seconds` | `3600` | How long an answer job keeps retrying rate limits and outages, counted from its scheduled slot. Values below 3600 are raised to 3600. |
| `tracking.stale_run_hours` | `6` | Hours without progress before a run's unanswered results are failed and the run is closed. |
| `tracking.max_queue_delay` | `900` | Longest single queue delay in seconds. Longer waits are split into steps. Keep 900 or less on SQS. |

### Alerts, import limits, reliability

| Key | Default | Meaning |
|---|---|---|
| `alerts.engine_alert_throttle_hours` | `6` | At most one "engine paused" alert per engine and reason in this many hours. `0` = no limit. |
| `limits.max_import_rows` | `5000` | Most rows read from one prompt CSV or paste import. `0` = no cap. |
| `reliability.failure_threshold` | `5` | Provider errors in a row before the engine pauses for an outage. |
| `reliability.outage_pause_minutes` | `15` | First outage pause. It doubles for each outage pause in a row. |
| `reliability.outage_pause_max_minutes` | `240` | Longest outage pause. |
| `reliability.credits_probe_minutes` | `360` | How often an engine paused for credits is re-tested. |
| `reliability.rate_limit_threshold` | `5` | Rate-limit responses in a row before the engine pauses. |
| `reliability.degraded_minutes` | `30` | How long a rate-limited engine runs at half speed, and the shortest rate-limit pause. |

### Health thresholds and HTTP

| Key | Default | Meaning |
|---|---|---|
| `health.scheduler_warning_after` | `5` | Minutes without a scheduler heartbeat before a warning. |
| `health.scheduler_critical_after` | `60` | Minutes before a failure (and the "scheduler stopped" alert). |
| `health.queue_warning_after` | `15` | Minutes without a queue heartbeat before a warning. |
| `health.queue_critical_after` | `60` | Minutes before a failure (and the "queue worker stopped" alert). |
| `http.timeout` | `60` | Seconds for each AI and SerpAPI HTTP request. |
| `http.website_fetch_timeout` | `10` | Seconds for fetching competitor websites (evidence). |
| `http.user_agent` | `Mozilla/5.0 (compatible; FilamentAiVisibility/1.0; ...)` | User agent for website fetches. |

## Default settings (`defaults`)

`defaults` holds the starting values for the Settings page. A value saved in the panel always wins. The config value is used only when the tenant has no saved value for that key (or saved an empty value).

In practice: once someone saves the Settings page, most keys are stored for that tenant, and later changes to `defaults` do not affect it. Use `defaults` to set the starting point for new tenants and new installs.

| Key | Default | Settings page field |
|---|---|---|
| `engines.enabled` | `[]` | Engines & API keys → Track this engine |
| `engines.models` | `[]` | Engines & API keys → Model for tracked prompts (per engine) |
| `engines.requests_per_minute` | `20` | Engines & API keys → Requests per minute, per engine |
| `engines.economy` | `false` | Engines & API keys → Economy mode for scheduled runs |
| `runs.samples` | `1` | Runs & limits → Samples per prompt (1–5) |
| `runs.frequency` | `weekly` | Runs & limits → Default run frequency (for new brands: `daily`, `weekly`, `manual`) |
| `runs.time` | `03:00` | Runs & limits → Run at (time of day for scheduled runs) |
| `limits.max_brands` | `null` | Runs & limits → Max brands |
| `limits.max_competitors_per_brand` | `20` | Runs & limits → Max competitors per brand |
| `limits.max_active_prompts_per_brand` | `50` | Runs & limits → Max active prompts per brand |
| `limits.max_keywords_per_brand` | `500` | Runs & limits → Max keywords per brand |
| `limits.max_runs_per_brand_per_day` | `2` | Runs & limits → Max runs per brand per day |
| `budget.monthly_usd` | `null` | Budget → Monthly budget (USD). `null` = no budget. |
| `budget.stop_at_budget` | `true` | Budget → Pause all engines when the budget is reached |
| `helpers.engine` | `auto` | AI helpers → Engine for analysis, classification and prompt generation (`auto` or an engine key) |
| `helpers.model` | `null` | AI helpers → Model (when an engine is pinned) |
| `discovery.enabled` | `true` | Analysis & competitors → Discover competitors in answers |
| `discovery.extract_names` | `true` | Analysis & competitors → Find names mentioned without a link |
| `discovery.classify` | `true` | Analysis & competitors → Classify the top candidates |
| `discovery.top_n` | `25` | Analysis & competitors → Candidates to classify per brand |
| `discovery.auto_accept` | `false` | Analysis & competitors → Automatically track high-confidence direct competitors |
| `discovery.reclassify_days` | `90` | Analysis & competitors → Re-check classifications after (days) |
| `discovery.ignored_domains` | `[]` | Analysis & competitors → Never suggest these domains |
| `discovery.allow_platforms` | `[]` | Not on the Settings page. Platforms (e.g. `amazon.com`) that are real competitors. Brand-overridable. |
| `analysis.enabled` | `true` | Analysis & competitors → Analyse answers |
| `keywords.sync_days` | `30` | Prompts & keywords → Sync keyword sources every (days) |
| `generation.count` | `10` | Prompts & keywords → Prompts per request |
| `generation.intents` | `discovery, comparison, alternatives, problem` | Prompts & keywords → Default intents (`branded` and `other` also exist) |
| `generation.persona` | `null` | Prompts & keywords → Default persona |
| `generation.ai_review` | `true` | Prompts & keywords → Review generated prompts with AI |
| `generation.min_quality` | `4` | Prompts & keywords → Minimum quality (1–5) |
| `instructions.*` | `null` | AI instructions tab. See the note below. |
| `alerts.user_ids` | `[]` | Alerts → Panel users who receive alerts |
| `alerts.emails` | `[]` | Alerts → Email alerts to |
| `alerts.slack_webhook` | `null` | Alerts → Slack incoming webhook URL |
| `alerts.database` | `true` | Alerts → Show alerts in the panel |
| `data.keep_answers_days` | `365` | Safety & data → Keep full answer text for (days). `ai-visibility:prune` (daily at 03:30) removes the text of older answers and their mention snippets; mentions, positions, sources, sentiment and costs are kept, so reports don't change. Empty or `0` keeps answers forever. |

Notes:

- Empty limit fields are saved as `0`, which means "no limit".
- Empty instruction fields are saved as an empty string, which means "built-in instruction". So `defaults.instructions.*` only applies to tenants that never saved the Settings page. To change an instruction for everyone, override the `Instructions` class ([Extending](extending.md#customising-ai-instructions)).
- Pause everything (kill switch) and the setup state are stored in their own columns, not in `defaults`.

## The Settings page

**AI Visibility → Settings** (users who can manage settings only). Everything is saved per tenant in the `ai_visibility_settings` table (one row per tenant). API keys are saved separately, encrypted with your `APP_KEY`, in `ai_visibility_provider_keys`, and are never sent back to the browser.

| Tab | Contains | Stored in |
|---|---|---|
| Engines & API keys | Per engine: Track this engine, API key (with **Test key** and **Remove saved key**), model. Requests per minute (applies to each engine separately). Economy mode for scheduled runs. | Settings row; keys in `provider_keys` |
| Runs & limits | Frequency, time, samples; all limits | Settings row |
| Budget | Monthly budget, stop at budget | Settings row |
| AI helpers | Helper engine (Automatic or pinned) and model | Settings row |
| Analysis & competitors | Analysis on/off, discovery options, ignored domains | Settings row |
| Prompts & keywords | Generation defaults, keyword sync interval | Settings row |
| AI instructions | Replacement text for each AI instruction. Empty = built-in. | Settings row |
| Alerts | In-panel on/off and recipients, emails, Slack webhook | Settings row |
| Safety & data | Pause everything; answer retention days | Kill switch column; settings row |

The header action **Run setup again** reopens the wizard.

What stays in config only: queues, table prefix, tenancy, model lists, prices, reliability thresholds, discovery tuning, source categories, markets, HTTP settings.

Reading and writing settings in code:

```php
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

Tenancy::as($tenantId, function () {
    $settings = app(Settings::class);

    $rpm = $settings->get('engines.requests_per_minute');   // saved value, else config default

    $settings->set(['budget.monthly_usd' => 100]);           // dot keys or nested arrays
    $settings->setKillSwitch(false);
});
```

Lists (such as `engines.enabled`) replace the stored list; they are not merged.

## Per-brand overrides

`brand_overridable` lists the settings a brand may override:

```php
'brand_overridable' => [
    'engines.enabled',
    'engines.models',
    'runs.samples',
    'limits.max_competitors_per_brand',
    'limits.max_active_prompts_per_brand',
    'limits.max_runs_per_brand_per_day',
    'budget.monthly_usd',
    'discovery.allow_platforms',
],
```

Overrides are stored in the brand's `settings` JSON column. When the brand has a filled value for a listed key, it wins; otherwise the tenant setting is used. Keys not in this list are always read from the tenant settings.

The **Settings** tab on a brand (only for users who can manage settings) edits these overrides:

| Brand field | Key |
|---|---|
| Engines | `engines.enabled` |
| Samples per prompt | `runs.samples` |
| Max active prompts | `limits.max_active_prompts_per_brand` |
| Max competitors | `limits.max_competitors_per_brand` |
| Max runs per day | `limits.max_runs_per_brand_per_day` |
| Monthly budget for this brand | `budget.monthly_usd` |

Not on the form, but honoured when set in code: `engines.models` (model per engine) and `discovery.allow_platforms`. A brand's run schedule comes from its own **Run frequency** field (Profile tab); the global `runs.frequency` is only the default for new brands.

A brand budget works alongside the tenant budget: the smaller remaining amount applies.

Removing a key from `brand_overridable` makes every brand use the tenant setting for it, even if an override is stored. Only saved values for listed keys are kept when a brand is saved.

Setting overrides in code:

```php
$brand->settings = [
    'engines' => ['enabled' => ['openai', 'anthropic'], 'models' => ['openai' => 'gpt-5.4']],
    'budget' => ['monthly_usd' => 25],
    'discovery' => ['allow_platforms' => ['amazon.com']],
];
$brand->save();
```
