# Changelog

## Unreleased — Milestone 8 (1.0)

- Google AI Overviews and Google AI Mode engines through SerpAPI, sharing one key (`AI_VISIBILITY_SERPAPI_KEY`). Separately loaded overviews are fetched automatically; searches without an AI answer are recorded, not failed. Key and quota problems pause the engines like any other.
- Economy mode: scheduled runs on OpenAI and Claude use the batch APIs at about half the token cost. Failed, expired, rejected or overdue batch answers are retried in real time; account errors pause the engine. New `ai-visibility:poll-batches` command (every 5 minutes) and a waiting notice on the run page.
- Engines that share a provider share a stored key; engines that cannot answer plain prompts are never picked for helper features.
- Perplexity moved to the Agent API (Sonar Chat Completions ends on September 27, 2026). Saved `sonar` / `sonar-pro` settings keep working.
- Current model lists, limited to models that search the web and return sources: GPT-6, Claude Fable 5.1 / Opus 5.5, Gemini 3.x, Grok 4.7. Prices updated.
- Claude always uses the basic web search tool, which works on every model and in batches and returns every source.
- Gemini 3 searches are counted and priced per query; Gemini 2.5 per prompt (`pricing.search_fee_models`).
- A model that can't search the web or doesn't exist pauses its engine with that reason instead of failing every answer.

## Unreleased — Milestone 7 (alerts and scheduled reports)

- Alert rules: visibility drop, competitor overtakes, new direct competitor, prompt lost, negative sentiment, budget threshold, run failed. Per-rule channels and cooldown; one alert per episode.
- Alerts inbox with unread badge; every alert (including always-on ones) is recorded.
- Always-on stall alerts for a stopped queue worker (watchdog every 10 minutes) and a stopped scheduler (checked from the panel).
- Scheduled weekly/monthly email reports with selectable sections, preview, send now, optional PDF (dompdf), failure alerts and retries.

## Unreleased — Milestone 6 (keywords, generation, topics)

- Keyword sources: SerpAPI "People also ask", Google Search Console (service account) and DataForSEO, with tests, scheduled syncs, keyword limits, failure states and one-time alerts. Custom sources via `->keywordSource()`.
- Keyword-grounded prompt generation with rule checks and optional AI quality review; suggestions saved for review, rejected ideas kept with reasons; prompts linked to their keywords.
- "Generate with AI" in the setup wizard; generation from the Prompts screen, a brand, or selected keywords.
- Topic grouping with review before applying; Topics report.
- Search-weighted reach on the Overview.
- Brand markets like "UK" now map to the correct country code (GB).

## Unreleased — Milestone 5 (analysis and competitor reports)

- Answer analysis: sentiment (with score), recommendation strength and descriptors for every detected brand and competitor mention; also collects other company names, replacing the separate extraction call.
- Deferred analysis when no helper engine is available, caught up automatically.
- Competitors report: leaderboard with period-over-period change, engine × brand visibility heatmap, brand perception.
- Head-to-head report against any competitor: visibility, prompts each wins, co-mention rate, descriptors, source gaps.
- Opportunities report: prompts where competitors are named without the brand, and the sites cited in those answers.
- Brand perception on the Overview; analysis shown on each answer.

## Unreleased — Milestone 4 (competitor intelligence)

- Helper AI calls (no web search) on every engine, with the same pausing, budgets and cost tracking as tracking; works with any single key.
- Name extraction: companies and products mentioned in answers without a link.
- Competitor discovery from names and cited domains (merged when they match), scored 0–100 by reach, engines, position and recency.
- Evidence-based classification into 11 labels using the candidate's website and how answers described it; confidence and reason stored.
- "Discovered" review screen: track, reject, ignore, relabel (single and bulk); tracking backfills past answers so share of voice updates immediately.
- The classifier learns from the user's label corrections per brand; optional auto-tracking of high-confidence direct competitors.
- Candidate labels categorise sources in reports.
- "Suggest competitors" in the setup wizard; editable AI instructions in Settings.
- `ai-visibility:discover` command; discovery runs after each run and daily.

## Unreleased — Milestone 3 (reports)

- Overview dashboard with brand, period, engine and topic filters: visibility, share of voice, citation rate and average position with period-over-period change; visibility trend per engine; share of voice; visibility by engine; top sources; prompts gained, lost and least visible; banner when tracking is interrupted.
- Sources report: source categories, top cited domains, and the brand's most-cited pages.
- Source categorisation (own, competitor, review, forum, media, social, marketplace, wiki, government/education), configurable.
- Prompt history page with per-engine timelines and what changed between answers.
- 30-day visibility column on prompts.
- CSV export of answers and prompts (spreadsheet-formula safe).

## Unreleased — Milestone 2 (tracking engine)

- Tracking runs on OpenAI, Anthropic, Gemini, Grok and Perplexity with web search enabled, localised to the brand's market.
- Mention detection (brand and competitors, positions, counts, snippets; ignores URLs, partial words and exclusion phrases) and source extraction with brand/competitor matching.
- Scheduled runs (daily/weekly at a set time), "Run now" with a cost estimate, and pre-run checks that refuse wasteful runs.
- Engine health: circuit breaker for outages, slow-down then pause for rate limits, automatic re-tests for exhausted credits, and instant skipping of paused engines with "Retry skipped".
- Monthly budgets (global and per brand) that stop spending mid-run and resume in the next month.
- Cost and usage tracking per call, using AI Monitor prices when installed.
- Runs and Answers screens; answers shown with the brand and competitors highlighted.
- `ai-visibility:run` and `ai-visibility:probe` commands, scheduled automatically.

## Unreleased — Milestone 1 (foundation)

- Setup wizard (system checks, engines and key tests, budget with cost estimate, brand pre-filled from its website, competitors, keywords, prompts, alerts).
- Engines: OpenAI, Anthropic, Gemini, Grok and Perplexity, with live key tests and automatic pausing when a key is missing, rejected, out of credits or the model is unavailable.
- Always-on engine alerts (in-panel, email, Slack), sent once per incident.
- Health page and `ai-visibility:health` with scheduler and queue heartbeats.
- Brands with aliases, domains, exclusion phrases and per-brand setting overrides; competitors, topics, prompts and keywords with bulk paste and CSV import.
- Settings with limits, monthly budget, helper-engine selection (works with a single key) and a "pause everything" switch.
- Optional AI Monitor integration for API keys.
- Multi-tenancy.
- Pest test suite; CI on Filament 4 and 5.
