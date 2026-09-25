# Changelog

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
