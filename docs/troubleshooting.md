# Troubleshooting

Start with **AI Visibility → Health**, or from the command line:

```bash
php artisan ai-visibility:health          # exits with 1 when something needs attention
php artisan ai-visibility:engines --test  # engine states, key sources, live key tests
```

- [Health page checks](#health-page-checks)
- [Paused engines](#paused-engines)
- [Runs do not start](#runs-do-not-start)
- [Runs are slow](#runs-are-slow)
- [Stuck runs and the sweeper](#stuck-runs-and-the-sweeper)
- [Jobs run twice or fail with "attempted too many times"](#jobs-run-twice-or-fail-with-attempted-too-many-times)
- [The brand is not detected in answers](#the-brand-is-not-detected-in-answers)
- [Competitor discovery: noise or nothing found](#competitor-discovery-noise-or-nothing-found)
- [Imports](#imports)
- [Keyword sources](#keyword-sources)
- [Alerts, emails and PDF reports](#alerts-emails-and-pdf-reports)
- [Costs look wrong](#costs-look-wrong)
- [Multi-server issues](#multi-server-issues)

## Health page checks

| Check | Problem | Fix |
|---|---|---|
| Database tables | Tables missing | `php artisan vendor:publish --tag="ai-visibility-migrations"` then `php artisan migrate`. If you changed `table_prefix` after migrating, rename the tables or change it back. |
| Queue connection | The connection uses `sync` | Set `QUEUE_CONNECTION=database` (or `redis`), or `AI_VISIBILITY_QUEUE_CONNECTION`, and start a worker. |
| Queue worker (per queue) | No heartbeat processed on that queue, or none for 15+ minutes (warning) / 60+ minutes (failed) | Start a worker for that queue name: `php artisan queue:work --queue=<name> --timeout=930`. Check the connection too: a worker on the wrong connection never sees the jobs. Click **Re-check** after starting it. |
| Scheduler | No heartbeat for 5+ minutes (warning) / 60+ (failed), or never | Add the cron entry `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`, or run `php artisan schedule:work` locally. |

Notes:

- The queue check turns green once a worker processes a heartbeat job. Opening the page sends one to each queue.
- During a big run, heartbeat jobs can wait behind many answer jobs. Look at the number of waiting jobs before assuming the worker stopped.
- "Everything is paused" at the top means **Settings → Safety & data → Pause everything** is on. Nothing runs until you switch it off.
- The engines table shows each engine's state, reason, key source and model. Users who can manage settings get **Test & resume** and **Pause** buttons.

## Paused engines

A paused engine is skipped straight away: its remaining answers in a run are marked **Skipped** with the reason, and no requests are sent. One alert is sent per pause (throttled by `alerts.engine_alert_throttle_hours`).

| Reason | Caused by | Resumes by itself? | Fix |
|---|---|---|---|
| No API key | No key in the panel, AI Monitor or env | Yes, as soon as a key is found | Add a key in **Settings → Engines & API keys**. If a saved key stopped working after changing `APP_KEY`, save it again (old keys cannot be decrypted). |
| Invalid API key | 401/403 or an "invalid key" error | No | Replace the key, then **Test & resume** on Health (or `php artisan ai-visibility:engines --resume=openai`). |
| Out of credits | 402, quota or billing errors; SerpAPI with no searches left | Yes: re-tested every `reliability.credits_probe_minutes` (6 hours) | Add credits or a payment method, then **Test & resume** to resume now. |
| Rate limited | `reliability.rate_limit_threshold` (5) rate-limit responses in a row | Yes, after `reliability.degraded_minutes` (30) or the provider's retry time | Lower **Requests per minute** in Settings. Before pausing, the engine runs at half speed ("Degraded") for 30 minutes. |
| Model unavailable | 404, "model not found", or the model cannot use web search | No | Pick another model in Settings (or on the brand), then **Test & resume**. |
| Provider outage | `reliability.failure_threshold` (5) server or connection errors in a row | Yes: re-tested after 15 minutes, then 30, 60… up to 240 | Nothing, usually. Check the provider's status page. |
| Budget reached | The tenant's monthly budget is used up | Yes, when there is budget again (new month or raised budget), checked every 5 minutes | Raise the budget in **Settings → Budget**, or wait. |
| Paused manually | Someone clicked **Pause** | No | **Test & resume**. |

After fixing an engine, open the run and click **Retry skipped** to collect the answers that were skipped. It re-queues them in real time.

The automatic checks are done by `ai-visibility:probe` every 5 minutes. If engines never come back on their own, check that the scheduler runs.

A success on a paused engine only lifts pauses that end by themselves (outage, rate limit, credits). Key, model, budget and manual pauses always wait for a person or the budget check.

## Runs do not start

**Run now** shows the reason when a run is refused. The same checks apply to scheduled runs (the reason is printed by `ai-visibility:run --due`):

| Message | Fix |
|---|---|
| Finish the AI Visibility setup first. | Complete the setup wizard, or set `require_setup` to `false` (see [Installation](installation.md#skipping-the-wizard-configuration-in-code)). |
| "Pause everything" is on in Settings. | Switch it off in Settings → Safety & data. |
| Tracking is off for {brand}. | Turn **Tracking on** for the brand (scheduled runs only). |
| {brand} has used its monthly budget. | Raise the brand budget or wait for next month. |
| The queue runs jobs synchronously. | Use a real queue driver (manual runs only). |
| {brand} has reached its limit of N runs today. | Raise **Max runs per brand per day** (Settings, or the brand's Settings tab). |
| No engines are enabled. | Turn on an engine in Settings (or on the brand's Settings tab). |
| Every enabled engine is paused. | See [Paused engines](#paused-engines). |
| {brand} has no active prompts to run. | Activate prompts (generated prompts are saved as **Suggested**). |
| This run would cost about $X, but only $Y of the monthly budget is left. | Raise the budget, reduce prompts, engines or samples, or wait for runs in progress to finish (their expected cost is reserved). |

Scheduled runs:

- Brands with frequency **Manual** never run on a schedule.
- Runs start at **Settings → Runs & limits → Run at** (default 03:00, app timezone), checked every 15 minutes. A daily brand runs once after each day's slot; a weekly brand runs when its last run is about a week old.
- If `ai-visibility:run` never runs, check the scheduler. A server that died mid-command can leave the "without overlapping" lock for up to 24 hours: `php artisan schedule:clear-cache`.
- Run by hand to see the messages: `php artisan ai-visibility:run --due` or `php artisan ai-visibility:run --brand=ID`.

## Runs are slow

Each answer waits 10–60 seconds on a web search, so one worker answers only a few prompts a minute. Add worker processes for the tracking queue and check the requests-per-minute setting. See [Queues and scheduler → Throughput](queues-and-scheduler.md#throughput-how-many-workers-you-need).

Economy mode answers arrive within 24 hours by design. The run page shows how many answers are still waiting in batches.

## Stuck runs and the sweeper

A run can stop making progress if its queue jobs were lost (a flushed queue, a crashed worker, a deleted job). `ai-visibility:sweep-runs` (hourly, and before every `ai-visibility:run --due`) closes runs where nothing has changed for `tracking.stale_run_hours` (6 hours):

- The unanswered answers are marked **Failed** with "Never answered: the queue job was lost."
- The run is closed as partial or failed, and `RunCompleted` fires (analysis and alerts follow).

A run is left alone while answers are still scheduled on the engine's pacing schedule, or while an economy batch is still within `economy.give_up_after_hours` + 2 hours.

Run it by hand:

```bash
php artisan ai-visibility:sweep-runs
```

Answers that ran out of retry time show "Not answered in time: the job ran out of retries." That happens when an engine stays rate-limited or down for longer than `tracking.retry_for_seconds` (1 hour) after the answer's slot.

## Jobs run twice or fail with "attempted too many times"

The queue connection's `retry_after` is shorter than the job. Laravel's default is 90 seconds; answer jobs can take up to 240 seconds and discovery jobs up to 900. A second worker then picks up a job that is still running.

Fix: set `retry_after` to at least 960 on the connection AI Visibility uses, and run workers with `--timeout=930`. See [Queues and scheduler](queues-and-scheduler.md#retry_after-and-worker-timeout-required).

AI Visibility protects you from paying twice: an answer is claimed before it is asked, one discovery runs per brand at a time, and an economy batch is never sent twice. But duplicate pickups still waste workers and produce errors in your logs.

## The brand is not detected in answers

Check the brand (Brands → edit):

- **Other names** (Detection tab): add abbreviations, product names and other spellings ("HubSpot CRM", "HS"). Matching is whole-word and case-insensitive.
- **Websites** (Profile tab): the brand's own domains. Sources on these domains count as "cited". A domain written as a name in text ("Booking.com is…") counts as a mention.
- **Ignore these phrases** (Detection tab): if the brand name is a common word, add phrases where it should not count ("the notion of").
- **Legal names**: "Nintendo Co., Ltd." also matches "Nintendo". The setup wizard stores the legal name as another name for you.

Past answers are re-checked automatically when you change a brand's name, other names, websites or ignored phrases, or add, remove or rename a competitor. This runs as `RedetectBrandJob` on the classification queue, so that queue needs a worker. To re-check by hand:

```bash
php artisan ai-visibility:redetect --brand=ID
php artisan ai-visibility:redetect            # every brand
```

Other causes:

- Google AI Overviews / AI Mode: "Google did not show an AI answer for this search." means Google showed no AI answer. It counts as an answer without mentions.
- The chosen model does not really search the web, so answers are generic. Use a model from the suggestions.
- The prompts are too generic for your brand. Generate prompts from keywords, or write more specific ones.

## Competitor discovery: noise or nothing found

Nothing is discovered:

- Discovery runs after each run with at least one answer, and daily at 04:30, as `DiscoverCompetitorsJob` on the **classification** queue. Check that a worker processes that queue.
- **Settings → Analysis & competitors**: "Discover competitors in answers" must be on. "Classify the top candidates" must be on for labels.
- The AI steps (analysis, name extraction, classification) need a helper engine. If none is usable (no key, paused, budget used up, "Pause everything"), they are skipped and logged ("competitor AI steps skipped"), and done on a later pass. Domains are still collected.
- Only answers from the last `discovery.window_days` (90) are used.
- Run it by hand and read the output: `php artisan ai-visibility:discover --brand=ID`.

Too much noise:

- On the **Discovered** screen, use **Not a competitor**, **Ignore forever** or **Change label**. Label corrections are shown to the classifier as examples next time.
- Add domains to **Settings → Analysis & competitors → Never suggest these domains** (per tenant) or `discovery.ignored_domains` in config (everyone).
- Add review sites, forums and media to `source_categories` in config. Domains in those categories are not candidates.
- `discovery.platform_domains` lists big platforms that are never competitors. If a platform is a real competitor for one brand (for example `amazon.com` for a retailer), add it to that brand's `discovery.allow_platforms` override.
- Lower **Candidates to classify per brand** to spend less on classification.
- **Automatically track high-confidence direct competitors** adds competitors without review. Turn it off if it adds the wrong ones.

## Imports

CSV imports (prompts and keywords):

- The first row is a header if it contains the column name (`text` for prompts, `keyword` for keywords). Also accepted: `prompt`, `prompts`, `question`, `questions` for prompts; `keywords`, `query`, `queries`, `top_queries`, `search_term`, `term` for keywords. Without a header, the first column is used.
- Commas, semicolons and tabs work. UTF-8 (with or without BOM), UTF-16 and Windows-1252 files are read correctly.
- Prompt imports read at most `limits.max_import_rows` (5000) rows; the rest are reported as over the limit.
- Prompts beyond the brand's active-prompt limit are saved as **Paused**, not dropped.
- Keywords beyond the brand's keyword limit are skipped; when there is no room at all, the import is refused with the limit message.
- Keywords and topic names longer than 255 characters, invalid text, and duplicates are skipped and counted in the result message.
- Imports are all-or-nothing: an error rolls back the whole file.

## Keyword sources

- A failing source is marked **Error** (or **Needs new credentials** when the key was rejected), sends one alert, and is retried the next day. The error is shown on the Keyword sources screen.
- Only users who can manage settings can test, sync and edit sources.
- SerpAPI "People also ask" needs seed keywords: set them on the connection, or add keywords to the brand first. Each seed costs one SerpAPI search.
- Google Search Console: enable the Search Console API for the service account's Google Cloud project, and add the service account's email as a user of the property.
- Sync runs daily at 05:00 for sources that are due (`keywords.sync_days`). Force it: `php artisan ai-visibility:sync-keywords --all` (or `--brand=ID`).
- A custom source that works in the panel but fails in the scheduled sync with "keyword source [x] is not registered" is registered only through the plugin option. Register it in the container ([Extending](extending.md#where-to-register-extensions)).

## Alerts, emails and PDF reports

In-panel alerts:

- The panel needs `->databaseNotifications()` and your app needs the `notifications` table (`php artisan make:notifications-table`, then migrate).
- Pick recipients in **Settings → Alerts → Panel users who receive alerts**. With nobody picked, alerts only go to the **Alerts** inbox.
- In-panel notifications are delivered immediately, not queued, so they arrive even when the worker is down.

Email alerts and reports:

- Both use your app's default mailer (`MAIL_*` settings). They are sent directly, not queued.
- Alert emails go to **Settings → Alerts → Email alerts to**. A failed send is logged as a warning and does not stop other channels.
- Scheduled reports are sent by `ai-visibility:send-reports` (hourly). A failed send is retried after 6 hours; after 3 failures in a row the schedule is paused and a panel alert explains why. Turn the schedule back on after fixing the mail setup.
- **PDF attachment**: install `dompdf/dompdf` (`composer require dompdf/dompdf`). Without it, reports are sent without the PDF.

Slack: paste an incoming webhook URL in Settings → Alerts. Failures are logged as warnings.

Always-on alerts (engine paused or resumed, keyword source failed, brand budget reached, queue worker stopped, scheduler stopped) cannot be switched off. The "scheduler stopped" alert is only checked when someone opens an AI Visibility screen, because a stopped scheduler cannot report itself.

## Costs look wrong

- Token prices come from AI Monitor when it is installed and knows the model, otherwise from `pricing.models` in your config. The bundled prices are estimates: compare them with each provider's pricing page.
- A model missing from `pricing.models` uses `pricing.fallback.{engine}`. Add it.
- Search fees are per search (`pricing.search_fee`); Gemini 2.5 is per grounded prompt (`pricing.search_fee_models`). Google AI Overviews can use two SerpAPI searches per answer.
- Estimates before a run use the average of recent real answers (at least 5), otherwise `estimated_cost_per_result`. The first runs' estimates can be off.

Details: [Engines and costs](engines-and-costs.md#how-costs-are-calculated).

## Multi-server issues

| Symptom | Cause | Fix |
|---|---|---|
| Engines get more requests than the limit; unique jobs duplicate; runs start twice | Each server has its own cache (`file` or `array`) | Use one shared cache store: `redis`, `database` or `memcached`. |
| Saved API keys "missing" on one server; decryption warnings in the log | Different `APP_KEY` values | Use the same `APP_KEY` everywhere, then save the keys again if needed. |
| Scheduled work done twice | `schedule:run` on several servers (the package does not use `onOneServer()`) | Run the scheduler on one server only. |
| A queue worker check fails on Health although workers run elsewhere | Workers listen on another connection or queue name, or use different env values | Use the same `AI_VISIBILITY_QUEUE_*` values on every server. |
| Custom engine works in the panel but jobs fail with "engine [x] is not registered" | Registered only via `->engine()`, which a worker may not see | Register it with `AiVisibilityPlugin::registerEngine()` in a service provider ([Extending](extending.md#where-to-register-extensions)). |
