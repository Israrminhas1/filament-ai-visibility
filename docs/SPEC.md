# Filament AI Visibility — Specification

**Package:** `israrminhas/filament-ai-visibility`
**Namespace:** `IsrarMinhas\FilamentAiVisibility`
**License:** MIT (free, open source)
**Supports:** Filament 4.x and 5.x, Laravel 11.28+, PHP 8.2+

## 1. What it does

A Filament plugin that tracks how brands show up in answers from AI assistants. Users bring their own API keys, add brands, and the plugin runs customer-style prompts on a schedule against OpenAI, Perplexity, Gemini, Claude and Grok (plus Google AI Overviews / AI Mode through SerpAPI). It records whether the brand was mentioned, where, how it was described, which sites were cited, and which competitors appeared, then turns that into competitor intelligence, opportunities and reports.

**Audience:** agencies, SEO/marketing teams and developers who want AI visibility tracking inside their own Laravel/Filament app instead of paying for a per-seat SaaS tool.

### Feature map

| Area | Features |
|---|---|
| **Setup** | Guided setup wizard, live key tests, system health checks (queue, scheduler); nothing runs until setup is complete |
| **Reliability** | Engine health tracking, automatic pausing on missing/invalid keys, exhausted credits, rate limits and outages; circuit breaker; alerts; guided recovery |
| **Tracking** | Brands, prompts (manual, CSV, keyword-grounded AI generation), scheduled and on-demand runs, samples, 7 engines |
| **Keywords** | Manual keywords, CSV, SerpAPI "People also ask", optional Google Search Console and DataForSEO; keyword-to-prompt tracing and volume-weighted reach |
| **Detection** | Mentions, position, citations, competitor mentions, exclusion phrases |
| **Analysis** | Sentiment, recommendation strength, descriptors, entity extraction, topic clustering |
| **Competitor intelligence** | Discovery (from citations *and* named entities), scoring, evidence-based classification, review queue, learning from overrides |
| **Citations** | Source categorisation (own, competitor, review site, forum, media, marketplace…), source gap analysis |
| **Reports** | Dashboard, competitor leaderboard, head-to-head, opportunities, provider matrix, topic report, answer history & diffs |
| **Automation** | Alert rules, scheduled email/PDF reports, events, batch "economy mode" |
| **Control** | Settings with per-brand overrides: limits, budgets, models, schedules, editable AI instructions; cost tracking by purpose; global kill switch; optional AI Monitor integration |
| **Customisation** | Swappable services, custom engines, keyword sources, labels, citation categories, alert channels and report pages; publishable views and translations |

Everything that calls an AI model beyond the tracked prompt itself (analysis, classification, generation, topic clustering) can be switched off individually, so users control their spend.

## 2. Setup and requirements

Users need:

- A **queue worker** (runs are queued jobs).
- The **scheduler** (`php artisan schedule:run` via cron).
- **One API key is enough.** With only an OpenAI key (or only any one engine key), the whole plugin works: tracking on that engine, and analysis, classification, prompt generation and topic clustering all run on it. More keys add more engines to track; nothing requires a specific provider.
- A SerpAPI key only for Google AI Overviews / AI Mode and "People also ask" keywords.
- Optional: `dompdf/dompdf` for PDF reports; Google Search Console or DataForSEO access for keyword grounding.

**Helper model resolution.** Every non-tracking AI feature (analysis, classification, generation, topic clustering, competitor suggestions, brand profile pre-fill) has a configurable engine + model, defaulting to **auto**: the first active engine in the order OpenAI → Anthropic → Gemini → Grok → Perplexity, using that engine's cheap default model. If the chosen engine pauses (§6.1), auto mode moves to the next active one; an explicitly chosen engine defers the work instead.

### 2.1 Setup wizard

Until setup is complete, every AI Visibility screen redirects to a **Setup** page (a Filament wizard), and no scheduled work runs. Progress is saved per step, so users can leave and come back. After completion the wizard stays available from Settings to re-run any step.

| Step | What happens |
|---|---|
| 1. **System check** | Verifies migrations, a non-`sync` queue connection, a queue worker actually processing jobs (a heartbeat job is dispatched and must come back), and the scheduler running (heartbeat written every minute). Each item shows pass/fail with the exact fix (cron line, `queue:work` command, Supervisor example). Blocking items must pass, or the user explicitly continues with warnings. |
| 2. **Engines and keys** | Pick engines, paste keys (or detect them from AI Monitor / env), choose models. A **Test** button per engine makes a minimal real call and reports: OK, invalid key, no credits, model not available, rate limited, or network error. Only engines that pass are enabled. |
| 3. **Budget and limits** | Monthly budget, per-brand budget, samples per prompt, run frequency. A live calculator shows the estimated monthly cost (prompts × engines × samples × frequency) at current model prices. |
| 4. **First brand** | Name and domain. The plugin fetches the website and pre-fills description, industry and market (editable), and suggests aliases from the site title and name variants. |
| 5. **Competitors** | Add manually and/or **Suggest competitors** (one AI call using the brand profile). Suggestions are reviewed before being added. |
| 6. **Keywords** (optional) | Type or paste keywords, and optionally connect SerpAPI "People also ask", Google Search Console or DataForSEO. Each connection is tested. The whole step can be skipped; generation then uses the brand profile alone. |
| 7. **Prompts** | Generate prompts from keywords (§11), review and activate. Shows the updated monthly cost estimate. |
| 8. **Alerts** | Where alerts go (email, Slack webhook, in-app) and which default rules to enable. Engine-pause, budget and stalled-system alerts are always on. |
| 9. **Review and start** | Summary with the cost estimate; **Start first run now** or **Wait for the schedule**. Marks setup complete. |

Developers can skip the wizard in code (`AiVisibilityPlugin::make()->withoutSetupWizard()`) and configure everything through config and seeders.

### 2.2 System health

A **Health** page (and a compact widget) always shows:

- Scheduler heartbeat age: warning after 5 minutes, critical after 1 hour.
- Queue heartbeat age and backlog per AI Visibility queue.
- Failed AI Visibility jobs in the last 24 hours.
- Each engine's state (§6.1), each keyword connection's state, and spend vs budget.

A stale scheduler or queue raises a `system_stalled` alert (§12.1), so "nothing has run for three days" never goes unnoticed.

## 3. Concepts

| Term | Meaning |
|---|---|
| **Brand** | The company/product being tracked. Name, aliases, domains, description, market. |
| **Competitor** | A tracked rival of a brand, with aliases and domains. Added manually or accepted from discovery. |
| **Candidate** | An entity or domain found in answers that might be a competitor, waiting for classification/review. |
| **Prompt** | A question a customer might ask an AI assistant, e.g. "best CRM for small agencies". |
| **Topic** | A cluster of related prompts (e.g. "Pricing", "Integrations"), assigned manually or automatically. |
| **Run** | One execution of a brand's active prompts across enabled providers. |
| **Result** | One answer: prompt × provider × model × sample within a run. |
| **Mention** | One brand or competitor named in a result, with position and analysis. |
| **Citation** | A URL the answer cited or linked, with a source category. |
| **Keyword** | A search query from a keyword source (typed, CSV, SerpAPI, GSC, DataForSEO), used to ground prompt generation. |
| **Connection** | A configured keyword/data source (e.g. a Search Console property), with its own health state. |
| **Engine state** | Whether an engine is active, degraded or paused, and why (§6.1). |

## 4. Data model

All tables use a configurable prefix (default `ai_visibility_`). Tenant-owned tables have a nullable, indexed `tenant_id` (see §15).

### Core

**`brands`** — id, tenant_id, name, aliases json, domains json, exclusions json (phrases that must not count as mentions), description text, industry, market (country/language), run_frequency (`daily`/`weekly`/`manual`), settings json (per-brand overrides of §13, e.g. engines, samples, budget), is_active, paused_reason nullable, last_run_at, timestamps.

**`competitors`** — id, brand_id, name, aliases json, domains json, exclusions json, category (see §8.3), source (`manual`/`discovered`), color (for charts), is_active, timestamps.

**`topics`** — id, brand_id, name, description, source (`manual`/`auto`), timestamps.

**`prompts`** — id, brand_id, topic_id nullable, text, tags json, intent (`discovery`/`comparison`/`alternatives`/`problem`/`branded`/`other`), source (`manual`/`generated`/`imported`), quality_score (1–5) nullable, quality_reason nullable, status (`suggested`/`active`/`paused`/`rejected`), last_run_at, timestamps.

**`keywords`** — id, tenant_id, brand_id, keyword, source (`manual`/`csv`/`gsc`/`dataforseo`/`serpapi_paa`/custom), connection_id nullable, search_volume, clicks, impressions, avg_position, intent, is_branded, status (`active`/`ignored`), metadata json, last_synced_at. Unique: (brand_id, normalised keyword).

**`keyword_prompt`** — keyword_id, prompt_id. Traces every generated prompt back to the keywords it came from.

**`connections`** — id, tenant_id, type (`gsc`/`dataforseo`/`serpapi`/custom), name, credentials (encrypted json: OAuth tokens, service-account JSON or API key), config json (GSC property, database/country, limits), status (`connected`/`needs_reauth`/`error`/`disabled`), last_error, last_synced_at, next_sync_at.

### Runs and results

**`runs`** — id, tenant_id, brand_id, trigger (`schedule`/`manual`), mode (`realtime`/`batch`), status (`pending`/`running`/`completed`/`partial`/`failed`/`stopped_budget`/`stopped_paused`), results_total, results_done, results_failed, results_skipped, estimated_cost_usd, cost_usd, started_at, finished_at.

**`results`** — id, tenant_id, run_id, brand_id, prompt_id, provider, model, sample, status (`pending`/`success`/`failed`/`skipped`), skip_reason nullable (e.g. `engine_paused:insufficient_credits`), analysis_status (`pending`/`done`/`deferred`/`off`), answer longtext, answer_hash (for change detection), brand_mentioned bool, brand_cited bool, brand_position nullable, brand_mention_count, brand_sentiment nullable, brand_recommendation nullable, input_tokens, output_tokens, cost_usd, error, ran_at.
Indexes: (brand_id, ran_at), (brand_id, provider, ran_at), (prompt_id, provider, ran_at).

**`mentions`** — id, result_id, subject_type (`brand`/`competitor`/`candidate`), subject_id, name_matched, position (1 = named first), count, sentiment (`positive`/`neutral`/`negative`) nullable, sentiment_score (-1..1) nullable, recommendation (`top_pick`/`recommended`/`listed`/`passing`/`cautioned`) nullable, descriptors json (e.g. `["affordable","easy to use"]`), snippet (sentence around the first mention).

**`citations`** — id, result_id, url, domain (registrable), title, position, category (see §9), competitor_id nullable, is_brand bool.

### Competitor intelligence

**`candidates`** — id, tenant_id, brand_id, kind (`domain`/`entity`), domain nullable, name, appearances, results_count, providers json (`{provider: count}`), prompts_count, avg_position, first_seen_at, last_seen_at, score (0–100), status (`new`/`classified`/`accepted`/`rejected`/`ignored`), linked_competitor_id nullable.
Unique: (brand_id, kind, domain/name normalised).

**`classifications`** — id, candidate_id, label (see §8.3), is_direct_competitor bool, confidence (`high`/`medium`/`low`), company_name, offering_summary, reason, evidence json (URLs + page titles used), model, classified_at, overridden_by nullable, override_label nullable.

**`domain_profiles`** — cache of fetched evidence per domain: domain (unique), title, meta_description, headings json, text_excerpt, fetched_at, status. Shared across brands and tenants (public web data only), refreshed after a configurable number of days.

### Automation and settings

**`alert_rules`** — id, tenant_id, brand_id nullable, type (§12.1), config json (thresholds, provider, competitor), channels json (`database`/`mail`/`slack`), recipients json, is_active, last_triggered_at.

**`alert_events`** — id, alert_rule_id, payload json, triggered_at, read_at.

**`report_schedules`** — id, tenant_id, brand_id, frequency (`weekly`/`monthly`), format (`email`/`pdf`/`both`), recipients json, sections json, next_send_at, last_sent_at.

**`settings`** — id, tenant_id (unique), settings json, setup_step, setup_completed_at, kill_switch bool. See §13.

**`engine_states`** — id, tenant_id, engine, status (`active`/`degraded`/`paused`/`disabled`), reason (`missing_key`/`invalid_key`/`insufficient_credits`/`rate_limited`/`model_unavailable`/`provider_outage`/`budget`/`manual`), message, consecutive_failures, paused_at, resume_after nullable, next_probe_at nullable, last_success_at, last_error_at, last_error json. One row per tenant × engine.

**`heartbeats`** — name (`scheduler`, `queue:{name}`), beat_at. Used by system health (§2.2).

**`provider_keys`** — only used when AI Monitor is **not** installed. provider, api_key (encrypted), is_active.

## 5. Providers

### Contract

```php
interface VisibilityProvider
{
    public function key(): string;              // 'openai'
    public function label(): string;            // 'OpenAI (ChatGPT)'
    public function defaultModel(): string;
    public function supportsBatch(): bool;
    public function ask(ProviderRequest $request): ProviderResponse;
}

final class ProviderRequest  { string $prompt; string $model; ?string $market; }
final class ProviderResponse { string $answer; array $citations; string $model; ?int $inputTokens; ?int $outputTokens; array $raw; }
```

Providers live in a `ProviderRegistry`; users add their own with `AiVisibilityPlugin::make()->provider(MyProvider::class)`.

### Built-in engines

A plain API call does not reflect what users see in ChatGPT or Perplexity, so every engine uses its web-search/grounding mode.

| Engine | API | Search mode | Citations from |
|---|---|---|---|
| OpenAI (ChatGPT) | Responses API | `web_search` tool | `url_citation` annotations |
| Perplexity | Sonar | Built in | `citations` / `search_results` |
| Gemini | `generateContent` | `google_search` grounding | `groundingMetadata` chunks |
| Claude | Messages API | Web search server tool | `web_search_result` blocks + text citations |
| Grok | xAI API | Web search tool | Response citations |
| Google AI Overviews | SerpAPI | Google SERP | Overview references |
| Google AI Mode | SerpAPI | AI Mode engine | References |

Exact tool names, versions and response shapes must be verified against each provider's docs at build time. Every engine gets recorded-response test fixtures. HTTP calls use Laravel's `Http` client, not vendor SDKs.

### Key resolution

1. AI Monitor installed → `ai_key($provider)`.
2. Otherwise → the plugin's `provider_keys` table.
3. Otherwise → `config('ai-visibility.keys.{provider}')` (env-based).

Engines without a key show as "Not configured" and are skipped.

### Cost tracking

- Tokens from each provider's usage fields; search-tool fees configurable as a flat per-call amount.
- AI Monitor installed → every call (tracking, analysis, classification, generation) is logged with `ai_log()`, `request_type` set to `ai-visibility:{purpose}`, and cost from AI Monitor pricing.
- Otherwise → per-model price table in `config('ai-visibility.pricing')`.
- Spend is reported per purpose (tracking / analysis / classification / generation) so users see where money goes.

## 6. Run pipeline

1. **Trigger.** `ai-visibility:run --due` runs hourly from the service provider and picks due brands. "Run now" actions run a brand, a topic, or selected prompts.
2. **Plan.** Results = active prompts × enabled engines × samples.
3. **Guard.** Setup complete and kill switch off; limits (§13); monthly and per-brand budgets; engine states (§6.1). Paused engines are left out of the plan. If no engine is usable the run is not created and the user is told why. If the estimated cost exceeds the remaining budget, the run is not started and a notification explains why.
4. **Dispatch.**
   - *Realtime mode:* one `RunResultJob` per result in a `Bus::batch`; `RateLimited` middleware per engine; 3 tries with backoff.
   - *Economy mode (batch):* for engines whose batch API supports the search tool, results are submitted as a provider batch job (typically ~50% cheaper, finishes within 24h) and polled by `ai-visibility:poll-batches`. Engines without batch support fall back to realtime. Verify each provider's batch + web-search support at build time.
5. **Execute.** Store answer and citations, then run the post-processing chain: detection (§7) → citation categorisation (§9) → analysis (§10, if enabled) → candidate discovery (§8.1).
6. **Budget stop.** If spend passes the cap mid-run, the batch is cancelled and the run marked `stopped_budget`.
7. **Finish.** Run totals/status updated; `RunCompleted` fired; alert rules evaluated (§12.1); starter notified for manual runs.

Queue names are configurable separately for tracking, analysis and classification.

### 6.1 Engine health, pausing and recovery

Jobs must never keep hammering an engine that cannot work. Every engine call goes through an **engine gate** (job middleware), and every failure through an **error classifier**.

**Before each call** the gate checks the engine state. If the engine is paused, the job does not call the API and does not retry: the result is marked `skipped` with the pause reason and the job ends. The remaining jobs for that engine in the batch are skipped the same way within seconds, so a paused engine costs nothing.

**After each failure** the classifier maps the provider response to a reason. Each provider has its own mapping (HTTP status plus error codes such as OpenAI's `insufficient_quota`, Anthropic's billing errors, Gemini's `RESOURCE_EXHAUSTED`), verified with recorded fixtures.

| Reason | Detected by | Action | Resumes |
|---|---|---|---|
| `missing_key` | Key resolver returns nothing (key deleted, env var removed, AI Monitor key deactivated) | Pause immediately | When a key is added and its test passes |
| `invalid_key` | 401 / 403 authentication errors | Pause immediately | After the user updates the key and **Test & resume** passes |
| `insufficient_credits` | 402 or quota/billing error codes | Pause immediately | Automatic probe (one minimal call) every 6 hours, or immediately via **Test & resume** |
| `model_unavailable` | 404 / model-not-found | Pause immediately; the alert lists available models | After the model is changed and the test passes |
| `rate_limited` | 429 without billing codes | Degrade: honour `retry-after` and halve that engine's request rate for 30 minutes; pause after 5 consecutive 429s | Automatically after the backoff |
| `provider_outage` | 5xx, timeouts, connection errors | Circuit breaker: after 5 consecutive failures pause for 15 minutes, then allow one probe call | Automatically when a probe succeeds; the pause doubles each time, up to 4 hours |
| `budget` | Tenant or brand budget reached | Pause all engines for that scope | Next budget period, or when the budget is raised |
| `manual` | User clicked **Pause** | Pause | When the user resumes |

**Alerts** (always on): an `engine_paused` alert is sent the moment an engine pauses — once per pause episode, not once per failed job — with the reason, the exact fix, and a link to the engine screen. `engine_resumed` is sent when it recovers.

**Runs** that lose an engine part-way finish with the remaining engines and end as `partial` (or `stopped_paused` if every engine paused). A **Retry skipped** action re-runs only the skipped results once the engine is back.

**Reports stay honest.** Skipped results are excluded from every metric, and charts show paused periods as shaded gaps with the reason on hover, so an outage never looks like a visibility drop.

**Other AI features** (analysis, classification, generation) go through the same gate for the engine they use. While it is paused, analysis is marked `deferred` and caught up automatically after resume; classification and generation actions explain why they cannot run.

**Kill switch.** Settings has **Pause everything**, which stops all scheduled and queued AI Visibility work for the tenant until it is turned off.

## 7. Detection

Pure PHP, deterministic, unit-tested. Runs on every successful result.

- **Mentions.** Case-insensitive, Unicode-aware word-boundary matching of names and aliases for the brand and each competitor, ignoring text inside URLs and inside the subject's exclusion phrases.
- **Position.** Order of first occurrence among all brands and competitors mentioned.
- **Snippet.** The sentence containing the first mention, stored on the mention for display and for analysis.
- **Citations.** URLs from provider citation data plus links in the answer text, normalised to registrable domains with the Public Suffix List (`jeremykendall/php-domain-parser`), matched against brand and competitor domains including subdomains.

## 8. Competitor intelligence

### 8.1 Discovery

Candidates come from two sources, so competitors are found even when they are named without a link:

- **Cited domains.** Every citation domain that is not the brand's own and not in the global ignore list (search engines, social networks, wikis — configurable) becomes or updates a `domain` candidate.
- **Named entities.** The analysis pass (§10) extracts company/product names mentioned in the answer. Names that are not the brand or a tracked competitor become or update an `entity` candidate. Entities are linked to a domain when the answer or citations associate them (e.g. a link on the name).

Each appearance updates counts, providers, prompts, average position and last seen.

### 8.2 Scoring

A 0–100 score ranks candidates for review and classification:

```
score = 100 × Σ(w_i × normalised_i)
  normalised: result share, engine coverage (engines seen ÷ engines enabled),
              prompt coverage, position (earlier is better), recency (decay over 30 days)
```

Weights are configurable. Only the top N candidates by score (default 25 per brand) are classified automatically, which keeps classification spend bounded.

### 8.3 Classification

Two stages, so the model judges real evidence rather than guessing from a domain name.

1. **Evidence.** For each candidate domain, fetch the homepage (and `/about` if available): title, meta description, headings, first ~2,000 characters of visible text. Stored in `domain_profiles` and reused for 30 days. Entity candidates without a domain use the answer snippets they appeared in. Optionally (setting), use a web-search-enabled model instead of fetching.
2. **Judgement.** A cheap model with structured JSON output classifies candidates in batches of 10, given the brand profile (name, description, industry, market, accepted competitors) and each candidate's evidence.

**Labels:**

| Label | Meaning |
|---|---|
| `direct_competitor` | Customers could choose it instead of the brand for the same core need |
| `indirect_competitor` | Solves the same problem differently or for an adjacent segment |
| `marketplace` | Lets customers buy/book the same offering from many sellers |
| `review_comparison` | Reviews, comparisons, "best X" lists (G2, Capterra, Trustpilot…) |
| `media_publisher` | News, blogs, magazines |
| `forum_community` | Reddit, Quora, forums, communities |
| `directory` | Listings without transactions |
| `tool_or_service` | Related tool that doesn't provide the core offering |
| `supplier_partner` | Sells to the brand or integrates with it |
| `own_property` | Belongs to the brand itself (unlisted domain, subsidiary) |
| `unrelated` | No meaningful relation |

Output per candidate: label, is_direct_competitor, confidence, company_name, offering_summary, reason, evidence URLs. Low-evidence candidates get `confidence: low` and are never auto-accepted.

**Review queue.** A "Discovered competitors" screen lists candidates by score with label, confidence, reason and evidence. Actions: **Accept** (creates/links a tracked competitor, copies aliases/domain), **Reject**, **Ignore forever**, **Change label**. Setting: auto-accept `direct_competitor` with `high` confidence (off by default).

**Learning from overrides.** When a user changes a label, the correction is saved and the most recent overrides for that brand are included as examples in the next classification prompt, so accuracy improves per brand.

**Re-classification.** Classifications older than a configurable age (default 90 days), or whose candidate's score jumped significantly, are re-queued.

The classification instruction is editable in settings, like the prompt-generation template.

## 9. Citation categorisation

Every citation gets a category: `own`, `competitor`, `review_comparison`, `marketplace`, `forum_community`, `social`, `media_publisher`, `wiki_reference`, `government_education`, `other`.

Order of resolution: brand domains → competitor domains → curated lists shipped with the plugin (e.g. reddit.com → forum_community, g2.com → review_comparison; users can extend them in config) → the candidate classification label, if one exists → `other`.

## 10. Answer analysis

An optional pass per result using a cheap model with structured output (can be switched off; detection still works without it):

- **Per mention** (brand and competitors): sentiment + score, recommendation strength (`top_pick` = named as the best choice, `recommended`, `listed`, `passing` = mentioned in passing, `cautioned` = warned against), and descriptors (short phrases the answer uses to describe it).
- **Entities:** other companies/products named in the answer, for discovery (§8.1).
- **Topic:** suggested topic for the prompt when it has none (feeds §11).

Results are stored on `mentions`. The analysis instruction is editable in settings.

## 11. Prompts and topics

### 11.1 Keyword sources

Prompts are only as good as the questions behind them, so generation is grounded in real search demand wherever possible. Keyword sources share one interface, so users can add their own:

```php
interface KeywordSource
{
    public function key(): string;
    public function label(): string;
    public function test(Connection $connection): ConnectionTestResult;

    /** @return iterable<KeywordData> keyword, volume?, clicks?, impressions?, position?, intent? */
    public function fetch(Connection $connection, Brand $brand, KeywordQuery $query): iterable;
}
```

| Source | What it provides | Notes |
|---|---|---|
| **Manual / CSV** | Keywords typed, pasted or imported | Always available |
| **Google Search Console** (optional) | Real queries for the brand's site with clicks, impressions and position (last 90 days) | Never required. The best free source for sites with traffic. Connect with OAuth (the user's own Google Cloud OAuth client, documented step by step) or a service account added to the property. Only useful for sites with traffic, and GSC queries are short keywords, so they are converted into conversational prompts (§11.2). |
| **DataForSEO** (optional) | Keywords for a site and competitors' sites, search volume, related keywords | Pay-as-you-go; lower priority |
| **SerpAPI "People also ask"** | Real question phrasings and related searches for seed keywords | Uses the same SerpAPI key as the AI Overviews engine |

Keyword handling:

- **Branded filter.** Queries containing the brand name or aliases are flagged `is_branded` and kept out of discovery prompts (they feed the "branded" intent instead).
- **Sync.** Connections sync monthly by default. New high-value keywords trigger a "new prompt suggestions available" notification.
- **Limits.** Max keywords per brand per source and minimum impressions/volume thresholds, so large sites don't import 50,000 queries.
- **Health.** Connections are treated like engines: expired OAuth tokens set `needs_reauth`, failed syncs set `error`, and both raise a `connection_failed` alert with a **Reconnect** link.

### 11.2 Grounded generation

Action **Generate prompts** (on a brand, a topic, or selected keywords):

1. **Select keywords** by source, topic, intent and volume/impressions threshold, or hand-pick them. Without keywords, generation falls back to the brand profile only and says so.
2. **Convert.** The generator model turns each keyword into 1–3 natural questions a customer would ask an AI assistant, across the chosen intents (discovery, comparison, alternatives, problem, branded), for the brand's market and an optional persona (e.g. "small agency owner"). Template placeholders: `{brand}`, `{description}`, `{industry}`, `{market}`, `{persona}`, `{competitors}`, `{keywords}`, `{count}`, `{topic}`, `{intents}`, `{existing_prompts}`.
3. **Quality gate**, so weak ideas never reach the list:
   - Rule checks: length bounds, no brand name (unless branded intent), must be a question or request, not a duplicate of an existing prompt (normalised text and word-overlap similarity).
   - AI review: each candidate is scored 1–5 for "would a real customer ask an AI assistant this?" and for relevance to the brand's offering, with a one-line reason. Below the threshold (default 4) it is saved as `rejected` in a collapsed "Rejected" tab, for transparency.
4. **Review.** Passing prompts are saved as `suggested` with their source keyword, its volume/impressions, the quality score and reason. Bulk activate, edit or reject. The active-prompt limit is enforced at activation.

Every prompt keeps its keyword links, which enables **volume-weighted reach** (§16): visibility weighted by the search demand behind each prompt, so winning a 10,000-search question counts more than winning a 10-search one.

### 11.3 Topic clustering

Action **Organise into topics**: sends the brand's prompts to the model, which proposes 3–12 topics and assigns each prompt. The user reviews the proposal before it is applied. New prompts are assigned to the closest existing topic by the analysis pass.

### 11.4 Import

CSV import (text, topic, tags, intent) via Filament's importer.

## 12. Automation

### 12.1 Alert rules

| Type | Fires when |
|---|---|
| `visibility_drop` | Brand visibility over the last N days falls by more than X points vs the previous N days (optionally per engine) |
| `competitor_overtakes` | A competitor's visibility or share of voice passes the brand's |
| `new_competitor` | A candidate is classified `direct_competitor` |
| `prompt_lost` | The brand disappears from a prompt where it appeared in the previous K runs |
| `negative_sentiment` | Share of negative brand mentions exceeds X% |
| `budget_threshold` | Monthly spend passes X% of the budget |
| `run_failed` | A run fails or more than X% of its results fail |
| `engine_paused` / `engine_resumed` | An engine pauses or recovers (§6.1). Always on. |
| `connection_failed` | A keyword connection needs re-authentication or its sync fails |
| `system_stalled` | The scheduler or queue heartbeat is stale (§2.2). Always on. |

Channels: Filament database notification, mail, Slack incoming webhook, plus any Laravel notification channel the user registers. Rules are evaluated after each run and daily. Each alert is sent once per episode, with a configurable cooldown.

### 12.2 Scheduled reports

Weekly or monthly per brand, sent by email and/or as a PDF attachment (requires `dompdf/dompdf`). Sections are selectable: summary, competitor leaderboard, opportunities, top sources, prompt movers, sentiment.

### 12.3 Events

`RunStarted`, `RunCompleted`, `RunStoppedByBudget`, `ResultRecorded`, `CandidateDiscovered`, `CandidateClassified`, `CompetitorAccepted`, `AlertTriggered`. Users can hook their own integrations.

## 13. Settings

A Filament settings page backed by the `settings` table, falling back to `config('ai-visibility.defaults.*')`.

| Group | Setting | Default |
|---|---|---|
| Engines | Enabled engines, model per engine | All with a key; engine defaults |
| Engines | Requests per minute per engine | 20 |
| Engines | Economy mode (batch APIs) | off |
| Runs | Samples per prompt | 1 (max 5) |
| Runs | Default frequency, time of day | weekly, 03:00 |
| Limits | Max brands / competitors per brand / active prompts per brand | unlimited / 20 / 50 |
| Limits | Max runs per brand per day | 2 |
| Limits | Monthly budget (USD), stop at budget | none, on |
| AI features | Answer analysis on/off, model | on, cheap default |
| AI features | Competitor classification on/off, model, top N per brand, auto-accept | on, cheap default, 25, off |
| AI features | Evidence source: fetch website / web-search model | fetch website |
| AI features | Prompt generator engine + model | auto (see §2) |
| Instructions | Generator, classifier, analysis and topic templates (editable, with "reset to default") | built-in |
| Discovery | Ignored domains, score weights | built-in list, default weights |
| Keywords | Sync frequency, max keywords per source, minimum volume/impressions, branded-keyword handling | monthly, 500, 10, excluded |
| Generation | Quality threshold, prompts per keyword, default intents, default persona | 4, 2, all non-branded, none |
| Reliability | Credit-probe interval, circuit-breaker threshold and cool-down, rate-limit backoff | 6h, 5 failures / 15 min, 30 min |
| Safety | Kill switch (pause everything), per-brand budget | off, none |
| Data | Keep answer text for N days (metrics kept) | 365 |

The settings page uses tabs, with rarely changed options under **Advanced**. Brands can override engines, models, samples, frequency, budget and limits in their own **Settings** tab, which shows which values are inherited and which are overridden.

Limits are enforced in actions, commands and jobs, not just the UI.

## 14. Filament UI

**Navigation group:** "AI Visibility" (configurable). Every screen can be disabled from the plugin.

| Screen | Contents |
|---|---|
| **Setup** wizard | §2.1. Replaces every screen until setup is complete. |
| **Health** page | §2.2: scheduler/queue heartbeats, engine states with **Test & resume** / **Pause** buttons, connection states, failed jobs, spend vs budget. A banner on every AI Visibility screen lists paused engines. |
| **Keywords** resource | Keywords per brand with source, volume/impressions, branded flag and linked prompts. Actions: sync now, generate prompts from selected, ignore. |
| **Connections** resource | Keyword/data sources with connect, test and reconnect, sync status and history. |
| **Overview** dashboard | Filters: brand, period, engine, topic. Stats: visibility, share of voice, average position, citation rate, sentiment, spend. Charts: visibility trend by engine; share-of-voice trend (stacked); sentiment trend. Tables: top movers (prompts gained/lost), top sources, recent alerts. |
| **Competitors** report | Leaderboard of brand + competitors: visibility, SoV, avg position, citation rate, sentiment, recommendation mix, change vs previous period. Engine × competitor heatmap. |
| **Head-to-head** report | Brand vs one competitor: visibility trends per engine, co-mention rate, prompts each one wins (named first), descriptors used for each, sources citing one but not the other. |
| **Opportunities** report | Prompts where competitors appear but the brand doesn't, ranked by competitor count × engine coverage; the sources cited in those answers ("get featured here"); sources that cite competitors but never the brand. |
| **Sources** report | Citation categories breakdown, top domains, own-site pages cited most, trend of review/forum sources. |
| **Topics** report | Visibility and SoV per topic; weakest topics first. |
| **Discovered competitors** | Review queue (§8.3) with evidence panel and bulk accept/reject/ignore. |
| **Brands** resource | Form + relation managers (Competitors, Topics, Prompts, Alert rules, Report schedules). Actions: Run now, Generate prompts, Organise into topics, Discover now. |
| **Prompts** resource | All prompts; filters: brand, topic, status, intent, source. Bulk: activate, pause, run now, move to topic. Columns: visibility %, trend sparkline, last result per engine. CSV import/export. |
| **Prompt detail** page | Per-engine answer history as a timeline, with diffs between consecutive answers and mention/citation changes highlighted. |
| **Results** resource (read-only) | Filters: brand, prompt, engine, mentioned, sentiment, date. View: answer with mentions highlighted, citations with categories, analysis, tokens, cost. |
| **Runs** resource (read-only) | Status, progress, mode, cost by purpose, duration. |
| **Alerts** | Rules resource + event inbox. |
| **Settings** page | §13. |
| **API keys** resource | Only when AI Monitor is not installed; write-only key field, masked in tables. |

Widgets also work on the user's own dashboards. Views use only Filament's built-in components and classes (no custom theme or Tailwind `@source` needed). Charts use Filament's Chart.js widgets.

## 15. Multi-tenancy

Global scope on `tenant_id`, resolved from a custom resolver, the `tenant()` helper, or `Filament::getTenant()`; resources set `$isScopedToTenant = false`. Settings, limits, budget, alerts and reports are per tenant. Scheduled work sets the tenant context from each brand before dispatching. `domain_profiles` are shared (public data only).

## 16. Report definitions

Over successful results in the selected period/brand/engine/topic. Skipped results (paused engines) are always excluded.

| Metric | Definition |
|---|---|
| Visibility % | Results mentioning the subject ÷ results |
| Citation rate | Results citing the subject's domains ÷ results |
| Average position | Mean position where mentioned |
| Share of voice | Subject mentions ÷ all tracked-subject mentions |
| Win rate | Results where the subject is named first ÷ results where it is mentioned |
| Co-mention rate | Results mentioning both A and B ÷ results mentioning A |
| Sentiment | Share of positive / neutral / negative mentions; mean score |
| Recommendation mix | Share of mentions by recommendation strength |
| Volume-weighted reach | Σ(prompt visibility × search demand of its keywords) ÷ Σ(search demand); unweighted when there is no keyword data |
| Opportunity score | Competitors mentioned × engines where the brand is missing, per prompt |

Trends group by day up to 90 days, by week above. Exports: CSV for every report table; PDF for scheduled reports.

## 17. Customisation and plugin API

Everything a user sees has a sensible default, and everything a developer might want to change has an extension point.

**Swappable services** (bind your own implementation in the container):

| Contract | Default | Purpose |
|---|---|---|
| `MentionDetector` | Rule-based detector | Mentions, positions, snippets |
| `CitationCategorizer` | Lists + classification labels | Source categories |
| `CandidateScorer` | Weighted score (§8.2) | Candidate ranking |
| `EvidenceFetcher` | Homepage/about fetcher | Classification evidence |
| `CompetitorClassifier` | LLM classifier | Candidate labels |
| `AnswerAnalyzer` | LLM analyzer | Sentiment, recommendation, entities |
| `PromptGenerator`, `PromptQualityGate` | LLM generator; rules + AI review | Prompt suggestions |
| `ErrorClassifier` | Per-engine mappings | Engine pause reasons |
| `CostCalculator` | AI Monitor or config prices | Spend |
| `TenantResolver` | Resolver chain (§15) | Tenancy |

**Registries** (add or remove items from the plugin or a service provider): engines, keyword sources, classification labels and citation categories (key, label, colour, icon, whether they count as competitors), alert rule types, alert channels, report pages and dashboard widgets, export formats.

**Content:** every AI instruction template is editable per tenant, with a live preview and **Test on a sample**. Views, config, migrations and translations (`lang/vendor/ai-visibility`) are publishable; model classes and the table prefix are configurable.

**Hooks:** events (§12.3) plus callbacks such as `AiVisibility::beforeRun()`, `AiVisibility::modifyResultUsing()` and `AiVisibility::modifyPromptUsing()`.

### Plugin configuration

```php
AiVisibilityPlugin::make()
    ->navigationGroup('AI Visibility')
    ->overview()->competitorsReport()->opportunitiesReport()   // each can be disabled
    ->discovery()->alerts()->scheduledReports()->settingsPage()
    ->provider(MyCustomEngine::class)
    ->withoutProvider('grok')
    ->keywordSource(MyKeywordSource::class)
    ->classificationLabels(fn (array $labels) => [...$labels, 'reseller' => [/* label, colour, icon */]])
    ->withoutSetupWizard();                                 // configure through code instead
```

**Artisan:** `ai-visibility:install`, `ai-visibility:health` (prints the §2.2 checks; non-zero exit code for monitoring), `ai-visibility:engines {--test} {--resume=}`, `ai-visibility:sync-keywords {--brand=}`, `ai-visibility:run {--due} {--brand=}`, `ai-visibility:poll-batches`, `ai-visibility:discover {--brand=}`, `ai-visibility:classify {--brand=} {--stale}`, `ai-visibility:alerts`, `ai-visibility:send-reports`, `ai-visibility:prune`.

## 18. Quality

- Pest + Testbench, CI on Filament 4 and 5.
- Engines tested with `Http::fake()` and recorded real responses; AI features tested with faked structured outputs.
- Detection, scoring and citation categorisation covered by table-driven unit tests.
- Report queries tested against seeded fixtures with known expected numbers.
- Larastan level 6+.

## 19. Milestones

Each milestone ends with a tagged release, so the plugin is usable early while the big features land.

| # | Release | Scope |
|---|---|---|
| 1 | 0.1 | Skeleton, migrations, tenancy, settings with per-brand overrides, API keys, Brands/Competitors/Topics/Prompts, CSV import, heartbeats + Health page, setup wizard (steps 1–5, 8–9) |
| 2 | 0.2 | Engine contract + 5 engines with key tests, engine gate + error classifier + pausing/recovery + always-on alerts, realtime run pipeline, limits and budget guard, detection, Results/Runs, AI Monitor integration |
| 3 | 0.3 | Overview dashboard, prompt detail with history/diffs, citations + categorisation, Sources report, CSV export |
| 4 | 0.4 | Competitor intelligence: discovery, scoring, evidence fetching, classification, review queue, learning from overrides |
| 5 | 0.5 | Analysis pass (sentiment, recommendation, descriptors, entities), Competitors, Head-to-head and Opportunities reports |
| 6 | 0.6 | Keywords and connections (manual/CSV, SerpAPI PAA, optional GSC and DataForSEO), grounded generation with quality gate, wizard steps 6–7, volume-weighted reach, topic clustering, Topics report |
| 7 | 0.7 | Alert rules, scheduled email/PDF reports, events |
| 8 | 1.0 | Economy (batch) mode, Google AI Overviews / AI Mode via SerpAPI, docs with screenshots, Filament directory listing |

## 20. Open questions

- Laravel floor: 11.28 (Filament's minimum) or 12+ only?
- Answer text retention default: 365 days, or keep forever?
- Should the Filament directory listing wait for 1.0, or go up at 0.3 as "beta"?
