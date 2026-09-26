# Installation

This page covers installing, upgrading and removing Filament AI Visibility.

- [Requirements](#requirements)
- [Install the package](#install-the-package)
- [Register the plugin](#register-the-plugin)
- [Start a queue worker and the scheduler](#start-a-queue-worker-and-the-scheduler)
- [First run: the setup wizard](#first-run-the-setup-wizard)
- [Skipping the wizard (configuration in code)](#skipping-the-wizard-configuration-in-code)
- [Upgrading](#upgrading)
- [Uninstalling](#uninstalling)

## Requirements

| Requirement | Version / notes |
|---|---|
| PHP | 8.2 or newer |
| Laravel | 11.28 or newer, up to 13 |
| Filament | 4.x (Livewire 3) or 5.x (Livewire 4) |
| Queue | Any real queue driver (`database`, `redis`, `sqs`…). `sync` does not work. |
| Scheduler | `php artisan schedule:run` every minute (cron), or `schedule:work` |
| Cache | A cache store that supports locks. Use `redis`, `database` or `memcached` when you have more than one server. |
| API key | At least one of: OpenAI, Anthropic, Google Gemini, xAI (Grok), Perplexity |
| Optional | A [SerpAPI](https://serpapi.com) key for the Google AI Overviews / AI Mode engines and "People also ask" keywords |
| Optional | `dompdf/dompdf` for PDF copies of scheduled email reports |
| Optional | [AI Monitor](https://github.com/Israrminhas1/filament-aimonitor) to share API keys and log every call's cost |

## Install the package

```bash
composer require israrminhas/filament-ai-visibility
php artisan ai-visibility:install
```

`ai-visibility:install` does three things:

1. Publishes `config/ai-visibility.php` (tag `ai-visibility-config`).
2. Publishes the migrations to `database/migrations` (tag `ai-visibility-migrations`).
3. Runs `php artisan migrate`.

To publish without migrating (for example, to change the table prefix first):

```bash
php artisan ai-visibility:install --no-migrate
# edit 'table_prefix' in config/ai-visibility.php, then:
php artisan migrate
```

All tables start with `ai_visibility_` by default. Change `table_prefix` **before** you migrate. See [Configuration](configuration.md#database-and-setup).

The migrations are not loaded from the package automatically. They must be published, which the install command does for you.

## Register the plugin

Add the plugin to each panel that should show AI Visibility:

```php
use Filament\Panel;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(AiVisibilityPlugin::make());
}
```

All plugin options (screens, navigation, permissions, extra engines) are in [Plugin options](plugin-options.md).

For in-panel alerts, the panel also needs Filament's database notifications and your app needs the `notifications` table:

```php
$panel->databaseNotifications();
```

```bash
php artisan make:notifications-table
php artisan migrate
```

Email and Slack alerts work without this.

## Start a queue worker and the scheduler

AI Visibility does its work in queued jobs and scheduled commands. Both must run.

For a first try, one worker is enough:

```bash
php artisan queue:work --timeout=930
```

Add the scheduler to cron on **one** server:

```cron
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

On a local machine (including Windows/Laragon), run the scheduler in a terminal instead:

```bash
php artisan schedule:work
```

Before you go to production, read [Queues and scheduler](queues-and-scheduler.md). It explains the `retry_after` setting you must raise, and how many workers you need.

## First run: the setup wizard

Open **AI Visibility** in your panel. Until setup is finished, every AI Visibility screen redirects to the wizard (except **Health**), and runs do not start. The rest of your panel is not affected.

Only users who can manage settings can open the wizard. See [Permissions and tenancy](permissions-and-tenancy.md).

| Step | What happens |
|---|---|
| 1. System | Checks the database tables, that the queue connection is not `sync`, that a worker processes jobs, and that the scheduler runs. Each failed check shows the exact fix. You can tick "Continue anyway". |
| 2. Engines | Turn engines on and paste keys. Each enabled engine's key is tested with a real, cheap request before you can continue. |
| 3. Budget | Run frequency, samples per prompt, active-prompt limit and monthly budget, with a live cost estimate. |
| 4. Brand | Enter the website. The name and description can be filled in from it. Legal suffixes are dropped from the name ("Nintendo Co., Ltd." becomes "Nintendo") and the full legal name is kept as another name. |
| 5. Competitors | The competitors you already know. You can ask the AI to suggest some. More are discovered later. |
| 6. Keywords | Optional search keywords, used to generate realistic prompts. |
| 7. Prompts | The questions to track. You can generate them with AI. |
| 8. Alerts | In-panel recipients, email addresses and a Slack webhook. |
| 9. Start | Review and finish. |

Progress is saved after every step. Finishing marks setup complete for the current tenant. **Settings → Run setup again** reopens the wizard later.

## Skipping the wizard (configuration in code)

If you configure everything in code, turn the wizard off and allow runs without it:

```php
AiVisibilityPlugin::make()->withoutSetupWizard();
```

```php
// config/ai-visibility.php
'require_setup' => false,
```

`withoutSetupWizard()` only removes the wizard page and the redirect. `require_setup` is what stops runs until setup is complete. With the wizard off, you must turn it off too, or mark setup as complete yourself:

```php
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

Tenancy::as($tenantId, function () {
    $settings = app(Settings::class);

    $settings->set([
        'engines' => ['enabled' => ['openai'], 'requests_per_minute' => 20],
        'runs' => ['frequency' => 'weekly', 'time' => '03:00', 'samples' => 1],
        'budget' => ['monthly_usd' => 50],
    ]);

    $settings->completeSetup();
});
```

Use `null` as the tenant ID for a single-tenant install. Keys can come from environment variables (`AI_VISIBILITY_OPENAI_KEY` and so on); see [Configuration](configuration.md#api-keys-from-the-environment).

## Upgrading

1. Read `CHANGELOG.md` for the version you are moving to.
2. Update the package:

   ```bash
   composer update israrminhas/filament-ai-visibility
   ```

3. Publish new migrations and run them:

   ```bash
   php artisan vendor:publish --tag=ai-visibility-migrations
   php artisan migrate
   ```

   Migrations you already published keep their file names and are not copied again. Only new ones are added.

4. Compare your config with the package's config. New keys fall back to built-in defaults in most places, but you should add them so you can see and change them. Do not overwrite your file blindly:

   ```bash
   diff config/ai-visibility.php vendor/israrminhas/filament-ai-visibility/config/ai-visibility.php
   ```

   Copy over new keys and any changed defaults you want (model lists and prices change often). If you have not changed anything, you can re-publish with `php artisan vendor:publish --tag=ai-visibility-config --force`.

5. If you published the views, compare them with the package's views too (`vendor/israrminhas/filament-ai-visibility/resources/views`).

6. Restart long-running processes so they load the new code:

   ```bash
   php artisan queue:restart     # plain workers
   php artisan horizon:terminate # Horizon
   php artisan config:cache      # if you cache config
   ```

Upgrade notes:

- The navigation is split into three groups by default ("AI Visibility", "AI Visibility · Tracking", "AI Visibility · Admin"). If you upgrade from a version with one group, add `->navigationGroups(false)` to keep one group.
- The per-purpose queue env vars (`AI_VISIBILITY_QUEUE_TRACKING`, `_ANALYSIS`, `_CLASSIFICATION`) all fall back to `AI_VISIBILITY_QUEUE`, so an existing single-queue setup keeps working. See [Queues and scheduler](queues-and-scheduler.md).

## Uninstalling

1. Turn on **Settings → Safety & data → Pause everything**, so no new work starts.
2. Remove `->plugin(AiVisibilityPlugin::make())` from your panel providers, and any `Tenancy::resolveUsing()` or container bindings you added.
3. Clear AI Visibility jobs from your queues. Otherwise workers fail on classes that no longer exist:

   ```bash
   php artisan queue:clear --queue=default   # or your AI Visibility queue names
   ```

   If AI Visibility shares a queue with your app, this also removes your app's jobs on that queue. Wait for the queue to drain instead if you are not sure.

4. Drop the tables. Roll back the published migrations (newest first), for example:

   ```bash
   php artisan migrate:rollback --path=database/migrations/2026_01_01_000007_add_ai_visibility_reliability_columns.php
   # ...repeat for each *_ai_visibility_* migration, newest first
   ```

   Or drop every table that starts with your `table_prefix` (default `ai_visibility_`).

5. Delete the published files: the `*ai_visibility*` migrations, `config/ai-visibility.php`, and `resources/views/vendor/ai-visibility` if you published views.
6. Remove the package:

   ```bash
   composer remove israrminhas/filament-ai-visibility
   ```

The scheduled tasks are registered by the package, so they disappear with it. In-panel alerts already delivered stay in your app's `notifications` table.
