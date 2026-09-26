# Filament AI Visibility

Track how your brand shows up in answers from ChatGPT, Claude, Gemini, Perplexity and Grok — with competitor intelligence, inside your own Filament panel. Bring your own API keys; one key is enough to start.

> **Status: in development (Milestone 6 of 8).** Setup, engines, brands, prompts, keywords, settings, health monitoring, tracking runs, reports, competitor intelligence, answer analysis, competitor reports and **keyword-grounded prompt generation with topics** work. Alert rules and scheduled reports land next. See [`docs/SPEC.md`](docs/SPEC.md) for the full plan.

## Requirements

- PHP 8.2+
- Laravel 11.28+ (tested up to Laravel 13)
- Filament 4.x (Livewire 3) or Filament 5.x (Livewire 4)
- A queue worker and the Laravel scheduler
- An API key for at least one of: OpenAI, Anthropic, Google Gemini, xAI (Grok), Perplexity

## Installation

```bash
composer require israrminhas/filament-ai-visibility
php artisan ai-visibility:install
```

Register the plugin in your panel provider:

```php
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(AiVisibilityPlugin::make());
}
```

Make sure a queue worker and the scheduler are running:

```bash
php artisan queue:work
# and in cron:
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

Then open **AI Visibility** in your panel. The setup wizard walks you through the rest.

## Setup wizard

Until setup is finished, every AI Visibility screen opens the wizard, and nothing runs in the background.

1. **System**: checks the database tables, that the queue isn't `sync`, that a worker is processing jobs, and that the scheduler is running, with the exact fix for anything missing.
2. **Engines**: turn on engines and paste keys. Each key is tested with a real (cheap) request before it's accepted.
3. **Budget**: run frequency, samples per prompt, active-prompt limit and monthly budget, with a live cost estimate.
4. **Brand**: enter the website and fill in the name and description from it.
5. **Competitors**: the ones you already know. More are discovered from AI answers later.
6. **Keywords** (optional): search keywords used to generate realistic prompts.
7. **Prompts**: the questions to track.
8. **Alerts**: in-panel, email and Slack.
9. **Start**.

Your progress is saved after every step.

## Tracking runs

Each run asks every active prompt on every enabled engine (× samples per prompt), with the engine's web search turned on so answers match what people see in the assistant:

| Engine | How it searches |
|---|---|
| OpenAI | Responses API with the `web_search` tool |
| Anthropic | Messages API with the web search server tool |
| Gemini | Grounding with Google Search |
| Grok | xAI Responses API with the `web_search` tool |
| Perplexity | Sonar (always searches) |

The brand's market (e.g. "United Kingdom" or "GB") is sent as the search location where the engine supports it.

For every answer AI Visibility records:
- whether the brand is mentioned, how often, and its position among all tracked brands
- which competitors are mentioned, with the sentence around each mention
- every cited source, and whether it's the brand's or a competitor's site
- tokens, searches and cost

Runs start on each brand's schedule (daily, weekly or manual, at the time set in Settings), or with **Run now** on a brand, which shows the answer count and estimated cost first. The **Runs** and **Answers** screens show progress and every answer, with the brand and competitors highlighted.

Before a run starts, it's refused (with the reason) if setup is unfinished, "Pause everything" is on, no engine is usable, there are no active prompts, the queue is `sync`, the daily run limit is reached, or the estimated cost exceeds the remaining budget.

## Reports

**Overview** (`/{panel}/ai-visibility`), filtered by brand, period (7 days to 12 months), engine and topic:

- **Visibility**: % of answers that mention the brand, with the change vs the previous period
- **Share of voice**: how often the brand is named, of all answers naming any tracked brand
- **Cited as a source**: % of answers citing the brand's site
- **Average position**: where the brand is named among tracked brands (1 = first)
- Visibility over time per engine, share of voice vs competitors, visibility by engine
- Top cited sources, and the prompts that gained, lost, or are least visible

**Sources** shows where answers get their information (your site, competitors, review sites, forums, media, social, marketplaces, wikis, government/education) and which of your pages are cited most. Add your own domains to the categories in `config/ai-visibility.php`.

**Prompt history** (open a prompt) shows each engine's answers over time and what changed: brand gained or lost, position moves, competitors appearing or disappearing, and new or dropped sources.

**CSV export** on Answers (every answer with mentions, sources and cost, matching the table's filters) and Prompts (30-day visibility per prompt).

Only successful answers count in reports. Answers skipped because an engine was paused are left out, and charts show those days as gaps rather than a drop to zero.

## Keywords and prompt generation

Prompts are only as good as the questions behind them, so generation is grounded in real search demand where possible.

**Keyword sources** (Keyword sources screen), all optional:

| Source | What it gives you | Notes |
|---|---|---|
| Manual / CSV | Keywords you paste or upload | Keywords screen |
| SerpAPI "People also ask" | Real question phrasings and related searches for your keywords | One SerpAPI search per seed keyword |
| Google Search Console | Your site's real queries, with clicks and impressions (90 days) | Free. Create a Google Cloud service account, enable the Search Console API, paste its JSON key, and add its email as a user of your property |
| DataForSEO | Keywords your site ranks for, with search volume | Pay as you go |

Each source can be tested and synced on demand, and syncs automatically (monthly by default). A failing source is marked (with the error), sends one alert, and retries the next day. Queries containing your brand name are flagged as branded and aren't used for discovery prompts.

**Generate prompts** (Prompts screen, a brand, the setup wizard, or selected keywords) writes realistic questions across the intents you choose (discovery, comparison, alternatives, problem solving, branded), for a persona and topic if you like. Weak ideas are filtered out first by rules (too short or long, not a question, names the brand, near-duplicate of an existing prompt), then by an optional AI review scoring realism and relevance 1–5. Good prompts are saved as **Suggested** for you to activate. Filtered ones are kept as **Rejected** with the reason. Each prompt remembers the keywords it came from.

**Organise into topics** (on a brand) proposes 3–12 topics for its prompts. You can rename or remove them before applying. The **Topics** report shows visibility per topic, weakest first.

When prompts are linked to keywords with search volume or impressions, the Overview adds **search-weighted reach**: visibility weighted by the demand behind each prompt, so winning a popular question counts more than winning a rare one.

## Answer analysis

After each run, answers are analysed in small batches (one AI helper call per ~5 answers). For every tracked brand an answer mentions, it records:

- **Sentiment**: positive, neutral or negative, with a score from -1 to 1
- **Recommendation strength**: top pick, recommended, listed, in passing, or cautioned against
- **Descriptors**: the words used to describe it ("affordable", "best for enterprises", "steep learning curve")

The same call also picks up other company names for competitor discovery, so no extra call is needed for that. Detection decides *whether* a brand is mentioned; the analysis only describes mentions detection found. If no helper engine is available, answers are marked for later and analysed on the next pass. Turn it off in **Settings → Analysis & competitors**.

## Competitor reports

- **Competitors**: a leaderboard of your brand and competitors (visibility with change, share of voice, average position, how often each is named first, how often its site is cited, net sentiment, top-pick rate), a heatmap of visibility per engine, and how AI talks about you (sentiment, recommendation mix, descriptors).
- **Head-to-head**: your brand against one competitor. Visibility, how often each is named ahead, how often they're named together, the prompts each one wins, how AI describes each, and the sites citing them but not you (and the reverse).
- **Opportunities**: prompts where competitors are named and you aren't, ranked by how many competitors appear on how many engines, plus **where to get featured**: the sites cited in exactly those answers.

## Competitor intelligence

After each run, AI Visibility looks for companies the AI engines mention alongside your brand:

1. **Names**: company and product names in the answers, even without a link (one AI helper call per ~8 answers; can be turned off).
2. **Sites**: every cited domain that isn't yours or a tracked competitor. Search engines and link shorteners are ignored, and you can add your own exclusions. A name and its domain ("Globex" and globex.io) become one candidate.
3. **Score** (0–100): how many answers, prompts and engines it appears in, how early it's named, and how recently.
4. **Classify**: the top candidates (25 per brand by default) are labelled from **evidence**: their homepage and about page, and the sentences the answers used to describe them. The labels are direct competitor, indirect competitor, marketplace, review/comparison, media, forum, directory, related tool, supplier/partner, your own property, or unrelated, each with a confidence and a reason.

The **Discovered** screen lists them by score. **Track** turns a candidate into a competitor and updates past answers, so it shows up in share of voice straight away. You can also mark candidates as **Not a competitor**, **Ignore forever**, or **Change label**. Label corrections are shown to the classifier as examples next time, so it learns what you mean. Labels also categorise sources (a site labelled "review/comparison" counts as a review source in the Sources report).

The setup wizard can **Suggest competitors** for a new brand, and **Settings → AI instructions** lets you replace the instructions used for classification, name extraction and suggestions.

## One key is enough

Every feature works with a single API key. Helper features (analysis, competitor classification, prompt generation) use the first engine with a working key, in the order OpenAI → Anthropic → Gemini → Grok → Perplexity, and switch automatically if that engine pauses. You can pin a specific engine and model in **Settings → AI helpers**.

Keys are found in this order:

1. Saved in the panel (**Settings → Engines**), stored encrypted and never sent back to the browser.
2. [AI Monitor](https://github.com/Israrminhas1/filament-aimonitor), if installed.
3. Environment variables: `AI_VISIBILITY_OPENAI_KEY`, `AI_VISIBILITY_ANTHROPIC_KEY`, `AI_VISIBILITY_GEMINI_KEY`, `AI_VISIBILITY_GROK_KEY`, `AI_VISIBILITY_PERPLEXITY_KEY`.

## When something goes wrong

Engines pause themselves instead of failing over and over:

| Problem | What happens |
|---|---|
| Key missing or removed | Engine pauses; resumes as soon as a key is added |
| Key rejected | Engine pauses until you fix the key and click **Test & resume** |
| Out of credits | Engine pauses; you're alerted immediately |
| Model unavailable | Engine pauses; pick another model |
| Rate limited | Engine slows down (half the requests per minute); pauses after repeated limits and resumes automatically |
| Provider outage | Requests retry with back-off; after 5 failures in a row the engine pauses for 15 minutes, then is re-tested (pauses grow up to 4 hours) |
| Out of credits | Re-tested every 6 hours and resumed automatically once credits are added |
| Monthly budget reached | Engines (or the brand, for a brand budget) pause; resume next month or when the budget is raised |

A paused engine is skipped instantly: its remaining answers are marked **skipped** with the reason, no requests are sent, and nothing is retried in a loop. Once it's fixed, **Retry skipped** on the run collects the missing answers.

Each pause sends **one** alert per incident (not one per failed request), in the panel, by email and to Slack, with the exact fix. These alerts are always on. The **Health** page shows every engine's state, the queue worker and the scheduler, and `php artisan ai-visibility:health` exits with an error code for your monitoring.

**Settings → Safety & data → Pause everything** stops all AI Visibility work at once.

## Limits

Set in **Settings**, and overridable per brand: max brands, competitors per brand, active prompts per brand, keywords per brand, runs per day, and a monthly budget. Limits are enforced everywhere (forms, imports, bulk actions and code), not just in the UI. Prompts imported over the active limit are saved as paused.

## Multi-tenancy

When `tenant_support` is on (the default), all data, settings, keys and engine states are scoped to the current tenant, resolved from:

1. A custom resolver: `Tenancy::resolveUsing(fn () => auth()->user()?->team_id);`
2. The `tenant()` helper (e.g. stancl/tenancy).
3. Filament's current panel tenant.

## Plugin options

```php
AiVisibilityPlugin::make()
    ->navigationGroup('Marketing')   // null for no group
    ->navigationSort(10)
    ->withoutSetupWizard()           // configure everything in code instead
    ->brands()->prompts()->keywords()->settingsPage()->healthPage()  // pass false to hide
    ->engine(MyEngine::class)        // add an engine (implements Engines\Contracts\Engine)
    ->keywordSource(MySource::class) // add a keyword source (implements Keywords\Contracts\KeywordSource)
    ->withoutEngine('grok');
```

## Artisan commands

| Command | Purpose |
|---|---|
| `ai-visibility:install` | Publish config and migrations, run migrations, print next steps |
| `ai-visibility:health` | Check the queue, scheduler and engines (non-zero exit when something needs attention) |
| `ai-visibility:engines {--test} {--resume=openai}` | Show engine states, test keys, or resume an engine |
| `ai-visibility:run {--due} {--brand=ID}` | Start due scheduled runs (runs every 15 minutes from the scheduler), or one brand now |
| `ai-visibility:probe` | Re-test paused engines and resume those that work (runs every 5 minutes) |
| `ai-visibility:discover {--brand=ID} {--queue}` | Find, score and classify competitors (runs after every run, and daily) |
| `ai-visibility:sync-keywords {--brand=ID} {--all}` | Pull keywords from connected sources (due ones run daily) |

## Costs

Every call is recorded with its tokens, searches and cost. Prices come from [AI Monitor](https://github.com/Israrminhas1/filament-aimonitor) when it's installed (and every call is also logged there), otherwise from `pricing` in `config/ai-visibility.php`. The bundled prices are estimates, so check them against each provider's pricing page.

## Testing

```bash
composer test
```

CI runs the suite on Filament 4 and 5.

## License

MIT. See [LICENSE](LICENSE).
