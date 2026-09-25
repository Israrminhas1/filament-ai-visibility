# Changelog

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
