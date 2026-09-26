# Filament AI Visibility documentation

Developer documentation for installing, configuring, running and extending the plugin. For a feature overview, see the [main README](../README.md).

| Page | Read it for |
|---|---|
| [Installation](installation.md) | Requirements, `ai-visibility:install`, registering the plugin, the setup wizard, skipping the wizard, upgrading, uninstalling. |
| [Queues and scheduler](queues-and-scheduler.md) | Every job and scheduled task, `retry_after` and worker timeouts, how many workers you need, Supervisor and Horizon configs, local setup on Windows, multi-server notes. |
| [Configuration](configuration.md) | Every key in `config/ai-visibility.php`, every env var, what the Settings page stores, per-brand overrides. |
| [Plugin options](plugin-options.md) | Every fluent method on `AiVisibilityPlugin`: navigation, screens, permissions, setup wizard, engines and keyword sources. |
| [Permissions and tenancy](permissions-and-tenancy.md) | What `authorizeUsing()` and `canManageSettings()` control, policies, alert recipients, multi-tenancy, shared keys, commands per tenant. |
| [Engines and costs](engines-and-costs.md) | Each engine's API, models, web search and sources; API keys; helper features; economy (batch) mode; how costs, estimates and budgets work. |
| [Extending](extending.md) | Custom AI engines, custom keyword sources, events, container bindings, AI instructions, views, translations. |
| [Troubleshooting](troubleshooting.md) | Health checks, paused engines, runs that don't start or get stuck, duplicate jobs, detection, discovery, imports, emails, multi-server problems. |
| [SPEC.md](SPEC.md) | The original design specification. |

## Quick start

```bash
composer require israrminhas/filament-ai-visibility
php artisan ai-visibility:install
```

```php
$panel->plugin(\IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin::make());
```

```dotenv
DB_QUEUE_RETRY_AFTER=960   # or REDIS_QUEUE_RETRY_AFTER=960
```

```bash
php artisan queue:work --timeout=930
php artisan schedule:work   # or the cron entry in production
```

Then open **AI Visibility** in your panel and follow the setup wizard.

## Artisan commands

The full list is in the [main README](../README.md#artisan-commands). Scheduled frequencies are in [Queues and scheduler](queues-and-scheduler.md#scheduled-tasks), and tenant behaviour in [Permissions and tenancy](permissions-and-tenancy.md#console-commands-and-jobs).
