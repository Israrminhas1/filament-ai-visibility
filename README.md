# Filament AI Visibility

Track how your brand shows up in answers from ChatGPT, Claude, Gemini, Perplexity and Grok — with competitor intelligence, inside your own Filament panel. Bring your own API keys; one key is enough to start.

> **Status: in development (Milestone 2 of 8).** Setup, engines, brands, prompts, keywords, settings, health monitoring and **tracking runs** work. Dashboards, reports and competitor intelligence land in the next milestones. See [`docs/SPEC.md`](docs/SPEC.md) for the full plan.

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

## Costs

Every call is recorded with its tokens, searches and cost. Prices come from [AI Monitor](https://github.com/Israrminhas1/filament-aimonitor) when it's installed (and every call is also logged there), otherwise from `pricing` in `config/ai-visibility.php`. The bundled prices are estimates, so check them against each provider's pricing page.

## Testing

```bash
composer test
```

CI runs the suite on Filament 4 and 5.

## License

MIT. See [LICENSE](LICENSE).
