# Filament AI Visibility

Track how your brand shows up in answers from ChatGPT, Claude, Gemini, Perplexity and Grok — with competitor intelligence, inside your own Filament panel. Bring your own API keys; one key is enough to start.

> **Status: in development (Milestone 1 of 8).** This release includes setup, engines and keys, brands, competitors, topics, prompts, keywords, settings, health monitoring and alerts. Tracking runs, reports and competitor intelligence land in the next milestones. See [`docs/SPEC.md`](docs/SPEC.md) for the full plan.

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

The pause-and-alert behaviour for rate limits, provider outages and budgets arrives with tracking runs in Milestone 2.

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

## Testing

```bash
composer test
```

CI runs the suite on Filament 4 and 5.

## License

MIT. See [LICENSE](LICENSE).
