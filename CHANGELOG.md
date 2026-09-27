# Changelog

## v1.1.1 — 2026-09-27

Fixes from an end-to-end run on two real brands with all seven engines.

- Keyword sources: a failed test is now kept on the source's status. Before, connecting a source whose test failed still showed "Connected" in the list, and **Test** never updated the status (in either direction).
- Keyword sources (Search Console, SerpAPI, DataForSEO) retry requests that fail to connect, like the engines do. A single TLS timeout to Google no longer fails a sync or a test.
- The Run now confirmation now names every reason a run cannot start (budget, no usable engines, no active prompts, daily limit, setup), not only some of them.
- "1 new keywords" is now "1 new keyword".

## v1.1.0 — 2026-09-27

More fixes from the end-to-end run, now with OpenAI and Claude, and economy mode is removed.

**Upgrading:** publish and run the new migrations (`php artisan vendor:publish --tag=ai-visibility-migrations && php artisan migrate`), then restart your queue workers. Answers keep being recorded if you haven't migrated yet.

### Removed: economy (batch) mode

Testing with live keys showed the batch APIs saved little: they discount tokens but not web-search fees, so OpenAI answers were about 5% cheaper and Claude answers about 35%, in exchange for answers arriving up to 24 hours later. Every run is now answered in real time.

- The "Economy mode for scheduled runs" setting, the `ai-visibility:poll-batches` command and its schedule, and the `economy` and `pricing.batch_discount` config keys are gone. You can delete those keys from a published config file; they are ignored.
- A migration drops the batches table. Answers that were still waiting in a batch are closed by the run sweeper after `tracking.stale_run_hours` (6 hours); then click **Retry unanswered** on the run.
- Custom engines: the `SupportsBatches` interface no longer exists. If your engine implements it, remove `implements SupportsBatches` (PHP fails to load the class otherwise); the batch methods can go too.

### Changed

- **Retry skipped** on a run is now **Retry unanswered**: it asks failed answers again as well as skipped ones, for example answers lost with a flushed queue.
- The Run now confirmation says when a run cannot start (for example the daily run limit is reached), instead of only after you click Start.
- Cost estimates before the first answers use measured averages: Claude ~$0.08, Gemini ~$0.09 and Grok ~$0.30 per answer were estimated far too low. See the table in `docs/engines-and-costs.md`.

### Fixed

- Answer page: "Cited in the answer" now uses what the engine reports. Claude, Perplexity and Grok return every page they read as well as the pages they cite; only the cited ones were meant to be listed as cited. Before, a source counted as cited only when its domain appeared in the answer text, so most of Claude's cited sources ended up under "Also read".
- Mention quotes were cut at the dot in a domain ("com (monday dev)" instead of "monday.com (monday dev)").

## v1.0.5 — 2026-09-27

Fixes from an end-to-end run with live API keys.

- Fixed: the redirect to the setup wizard never fired in a real browser, because it ran before the session had started. It now runs with the panel's auth middleware.
- Requests that fail to connect (for example a TLS timeout) are retried twice before failing. Set `http.connect_retries` in the config to change this.
- Fixed: helper features on Gemini sometimes returned cut-off JSON, because thinking used up the output limit. Gemini now gets extra room for thinking (`engines.gemini.thinking_headroom`, 8192 tokens by default); only tokens actually used are billed.
- Competitor candidates that could not be classified are retried after 1 day instead of 7.
- Google AI Overview and AI Mode: sources shown as `google.com/goto` links now point to the real site, and escaped characters (such as `built\-in` and unicode escapes) are cleaned from answers and titles.
- Answer page: mentions found through entity detection no longer show a made-up position, and snippets from tables read as plain text.
- Setup: the cost estimate and review step make sense for manual runs, and the finish message no longer mentions a schedule when there is none.
- Brand settings list engines by name, and the health check says "1 job waiting" rather than "1 jobs waiting".

## v1.0.4 — 2026-09-27

- Google Search Console: the property is found automatically from the brand's domain (a domain property such as `sc-domain:acme.com` first, then `https://www.acme.com/`), so it no longer has to be typed in. When nothing matches, the error names the exact service-account email to add in Search Console.

## v1.0.3 — 2026-09-27

- Gemini: only models that reliably search and return sources are offered (`gemini-3.5-flash`, now the default, `gemini-3.5-flash-lite`, `gemini-2.5-flash`). Tested against the live API: Gemini 3.8, 3.7 and 3.1 Pro often answered without searching, and `gemini-2.5-pro` is no longer available to new users.
- Gemini requests now include an instruction to search before answering.

## v1.0.2 — 2026-09-27

- Fixed: Perplexity sometimes answered from memory, with no web search and no sources. Requests now tell it to search first.
- Fixed: Perplexity search counts are read from `search_web`, the field the API actually returns.

## v1.0.1 — 2026-09-27

- Fixed: the setup wizard's Prompts step failed on Laravel 11 and 12 (it used a query method that only exists in Laravel 13).
- Laravel 11 is now part of the automated test matrix.

## v1.0.0 — 2026-09-27

First stable release.

- The visibility chart connects runs on weekly schedules instead of showing scattered dots; Google engines have their own colours.

## v1.0.0-beta.1 — 2026-09-26

First public beta. It includes everything in the sections below.

Also in this release:

- Separate queues per purpose (`AI_VISIBILITY_QUEUE_TRACKING`, `_ANALYSIS`, `_CLASSIFICATION`), with a worker check, waiting-job count and stopped-queue alert for each.
- `AiVisibilityPlugin::registerEngine()`, `removeEngine()` and `registerKeywordSource()` for service providers, so custom engines and keyword sources work in queue workers and scheduled commands.
- `ai-visibility:prune` removes the text of answers past "Keep full answer text for", keeping every metric.
- Developer documentation in `docs/`.

## Review fixes before 1.0

An independent review of every module found about 30 issues; all blocking ones are fixed.

- Detection: legal names ("Nintendo Co., Ltd.") now match everyday use ("Nintendo"); the longest name wins when a competitor contains the brand name; Chinese, Japanese, Korean and Thai names are found; curly apostrophes, emails and bare domains handled; tracking parameters removed from sources.
- Past answers are checked again automatically when a brand's names or domains or its competitors change (`ai-visibility:redetect`), keeping their analysis.
- Answers list and answer page redesigned: rendered answer with highlighted brand and competitors, summary strip, ranked mentions, sources split into cited and read.
- API keys: Settings tab renamed "Engines & API keys", remove-key action, one shared SerpAPI key field, links from Health, the paused-engine banner and key alerts.
- Permissions: `->authorizeUsing()` and `->canManageSettings()` plugin options; alert-recipient picker scoped to the tenant (`->alertRecipientsQuery()`); navigation split into Reports, Tracking and Admin groups.
- Competitors: "Discover now" and "Classify again" run in the background; no cross-tenant evidence; safe website fetching (no private addresses, checked redirects, size cap); the brand's own products and common platforms are no longer candidates; accent-insensitive candidate keys (MySQL).
- Runs: no stuck runs (`ai-visibility:sweep-runs`), no double-paid answers, rate-limit pacing for large runs, budgets include the cost of runs still in progress, cost estimates learn from real averages.
- Engines: "Test & resume" keeps the automatic check, outage back-off doubles, manual and budget pauses are never lifted by a success, throttled pause alerts, Gemini rate limits no longer read as "no credits", Claude's text before a search is its own paragraph.
- Reports and alerts: "All brands" rules alert per brand, share of voice counts tracked brands only, "prompt lost" compares whole runs, calendar-month and full-week report periods, no double sends, dark-mode colours.
- Keywords: Excel (BOM) and Windows-1252 CSVs import correctly, imports are all-or-nothing, generated prompts never contain the brand name unless branded prompts were asked for, SerpAPI seed loop fixed.

## Milestone 8 (1.0)

- Google AI Overviews and Google AI Mode engines through SerpAPI, sharing one key (`AI_VISIBILITY_SERPAPI_KEY`). Separately loaded overviews are fetched automatically; searches without an AI answer are recorded, not failed. Key and quota problems pause the engines like any other.
- Economy mode: scheduled runs on OpenAI and Claude use the batch APIs at about half the token cost. Failed, expired, rejected or overdue batch answers are retried in real time; account errors pause the engine. New `ai-visibility:poll-batches` command (every 5 minutes) and a waiting notice on the run page.
- Engines that share a provider share a stored key; engines that cannot answer plain prompts are never picked for helper features.
- Perplexity moved to the Agent API (Sonar Chat Completions ends on September 27, 2026). Saved `sonar` / `sonar-pro` settings keep working.
- Current model lists, limited to models that search the web and return sources: GPT-6, Claude Fable 5.1 / Opus 5.5, Gemini 3.x, Grok 4.7. Prices updated.
- Claude always uses the basic web search tool, which works on every model and in batches and returns every source.
- Gemini 3 searches are counted and priced per query; Gemini 2.5 per prompt (`pricing.search_fee_models`).
- A model that can't search the web or doesn't exist pauses its engine with that reason instead of failing every answer.

## Milestone 7 (alerts and scheduled reports)

- Alert rules: visibility drop, competitor overtakes, new direct competitor, prompt lost, negative sentiment, budget threshold, run failed. Per-rule channels and cooldown; one alert per episode.
- Alerts inbox with unread badge; every alert (including always-on ones) is recorded.
- Always-on stall alerts for a stopped queue worker (watchdog every 10 minutes) and a stopped scheduler (checked from the panel).
- Scheduled weekly/monthly email reports with selectable sections, preview, send now, optional PDF (dompdf), failure alerts and retries.

## Milestone 6 (keywords, generation, topics)

- Keyword sources: SerpAPI "People also ask", Google Search Console (service account) and DataForSEO, with tests, scheduled syncs, keyword limits, failure states and one-time alerts. Custom sources via `->keywordSource()`.
- Keyword-grounded prompt generation with rule checks and optional AI quality review; suggestions saved for review, rejected ideas kept with reasons; prompts linked to their keywords.
- "Generate with AI" in the setup wizard; generation from the Prompts screen, a brand, or selected keywords.
- Topic grouping with review before applying; Topics report.
- Search-weighted reach on the Overview.
- Brand markets like "UK" now map to the correct country code (GB).

## Milestone 5 (analysis and competitor reports)

- Answer analysis: sentiment (with score), recommendation strength and descriptors for every detected brand and competitor mention; also collects other company names, replacing the separate extraction call.
- Deferred analysis when no helper engine is available, caught up automatically.
- Competitors report: leaderboard with period-over-period change, engine × brand visibility heatmap, brand perception.
- Head-to-head report against any competitor: visibility, prompts each wins, co-mention rate, descriptors, source gaps.
- Opportunities report: prompts where competitors are named without the brand, and the sites cited in those answers.
- Brand perception on the Overview; analysis shown on each answer.

## Milestone 4 (competitor intelligence)

- Helper AI calls (no web search) on every engine, with the same pausing, budgets and cost tracking as tracking; works with any single key.
- Name extraction: companies and products mentioned in answers without a link.
- Competitor discovery from names and cited domains (merged when they match), scored 0–100 by reach, engines, position and recency.
- Evidence-based classification into 11 labels using the candidate's website and how answers described it; confidence and reason stored.
- "Discovered" review screen: track, reject, ignore, relabel (single and bulk); tracking backfills past answers so share of voice updates immediately.
- The classifier learns from the user's label corrections per brand; optional auto-tracking of high-confidence direct competitors.
- Candidate labels categorise sources in reports.
- "Suggest competitors" in the setup wizard; editable AI instructions in Settings.
- `ai-visibility:discover` command; discovery runs after each run and daily.

## Milestone 3 (reports)

- Overview dashboard with brand, period, engine and topic filters: visibility, share of voice, citation rate and average position with period-over-period change; visibility trend per engine; share of voice; visibility by engine; top sources; prompts gained, lost and least visible; banner when tracking is interrupted.
- Sources report: source categories, top cited domains, and the brand's most-cited pages.
- Source categorisation (own, competitor, review, forum, media, social, marketplace, wiki, government/education), configurable.
- Prompt history page with per-engine timelines and what changed between answers.
- 30-day visibility column on prompts.
- CSV export of answers and prompts (spreadsheet-formula safe).

## Milestone 2 (tracking engine)

- Tracking runs on OpenAI, Anthropic, Gemini, Grok and Perplexity with web search enabled, localised to the brand's market.
- Mention detection (brand and competitors, positions, counts, snippets; ignores URLs, partial words and exclusion phrases) and source extraction with brand/competitor matching.
- Scheduled runs (daily/weekly at a set time), "Run now" with a cost estimate, and pre-run checks that refuse wasteful runs.
- Engine health: circuit breaker for outages, slow-down then pause for rate limits, automatic re-tests for exhausted credits, and instant skipping of paused engines with "Retry skipped".
- Monthly budgets (global and per brand) that stop spending mid-run and resume in the next month.
- Cost and usage tracking per call, using AI Monitor prices when installed.
- Runs and Answers screens; answers shown with the brand and competitors highlighted.
- `ai-visibility:run` and `ai-visibility:probe` commands, scheduled automatically.

## Milestone 1 (foundation)

- Setup wizard (system checks, engines and key tests, budget with cost estimate, brand pre-filled from its website, competitors, keywords, prompts, alerts).
- Engines: OpenAI, Anthropic, Gemini, Grok and Perplexity, with live key tests and automatic pausing when a key is missing, rejected, out of credits or the model is unavailable.
- Always-on engine alerts (in-panel, email, Slack), sent once per incident.
- Health page and `ai-visibility:health` with scheduler and queue heartbeats.
- Brands with aliases, domains, exclusion phrases and per-brand setting overrides; competitors, topics, prompts and keywords with bulk paste and CSV import.
- Settings with limits, monthly budget, helper-engine selection (works with a single key) and a "pause everything" switch.
- Optional AI Monitor integration for API keys.
- Multi-tenancy.
- Pest test suite; CI on Filament 4 and 5.
