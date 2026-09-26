# Permissions and tenancy

- [Permissions](#permissions)
  - [authorizeUsing()](#authorizeusing)
  - [canManageSettings()](#canmanagesettings)
  - [Policies](#policies)
  - [Alert recipients](#alert-recipients)
- [Multi-tenancy](#multi-tenancy)
  - [How the tenant is resolved](#how-the-tenant-is-resolved)
  - [What is scoped to a tenant](#what-is-scoped-to-a-tenant)
  - [Filament tenancy](#filament-tenancy)
  - [Keys and billing across tenants](#keys-and-billing-across-tenants)
  - [Console commands and jobs](#console-commands-and-jobs)
  - [Running code as a tenant](#running-code-as-a-tenant)

## Permissions

By default, everyone who can use the panel can use every part of AI Visibility. Two plugin options narrow that down:

```php
AiVisibilityPlugin::make()
    // Who can open AI Visibility at all.
    ->authorizeUsing(fn ($user) => $user->can('view-ai-visibility'))
    // Who can change settings, keys, budgets and alerts.
    ->canManageSettings(fn ($user) => $user->is_admin);   // or true / false
```

Both closures receive the logged-in user and return a bool. They are never called for guests: a guest is simply not authorised.

### authorizeUsing()

Gates every AI Visibility screen: reports, brands, prompts, keywords, keyword sources, discovered candidates, runs, answers, the alerts inbox, Settings, Setup, alert rules, scheduled reports and Health. Screens the user cannot open are hidden from the navigation and return 403. The paused-engines banner widget is also hidden.

A user who fails `authorizeUsing()` also fails `canManageSettings()`.

### canManageSettings()

Default `true` (everyone who passes `authorizeUsing()`). When it returns `false` for a user:

| Area | Effect |
|---|---|
| Settings page | Hidden, 403. |
| Setup wizard | Hidden, 403. The user is not redirected to it, so they can use the other screens before setup is finished. **Run setup again** is hidden. |
| Alert rules | Hidden, 403. |
| Scheduled reports | Hidden, 403. |
| Keyword sources | The list is visible. Connecting, editing, testing, syncing and removing sources is not (they hold API keys). |
| Brands → Settings tab | Hidden. Per-brand engines, samples, limits and budget. If the user saves the brand, stored overrides are kept unchanged. |
| Health | Page visible. The **Pause**, **Test & resume** buttons and the link to the API keys are hidden, and the actions return 403. |
| Paused-engines banner | Shown, without the "fix the key in Settings" link. |

Everything else follows `authorizeUsing()` only: reports, brands (profile and detection), competitors, prompts, keywords, topics, discovered candidates (track, reject, classify again), runs (**Run now**, **Retry skipped**), answers and exports, and the alerts inbox.

`AiVisibilityPlugin::userCanManage()` returns `true` outside a panel (console commands, queued jobs), so background work is never blocked by these checks.

### Policies

The package ships no policies. Filament's normal checks still run on top of the two options above: if your app registers a policy for an AI Visibility model (for example `IsrarMinhas\FilamentAiVisibility\Models\Brand`), Filament applies it to that resource as usual. A screen is shown only when both the plugin check and Filament's check allow it.

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;

public function boot(): void
{
    Gate::policy(Brand::class, \App\Policies\AiVisibilityBrandPolicy::class);
}
```

### Alert recipients

**Settings → Alerts → Panel users who receive alerts** only offers users the current user should see:

1. The plugin's `alertRecipientsQuery()` closure, if set.
2. With Filament tenancy: the current tenant's `users()` or `members()` relationship. Without such a relationship: only the current user.
3. Without tenancy: the list starts with the current user; others are found by searching name, email or ID.

```php
AiVisibilityPlugin::make()
    ->alertRecipientsQuery(fn ($query, $tenant) => $query->where('team_id', $tenant?->getKey()));
```

Saved recipients who are no longer in the allowed set are removed when the form opens (with a notice), and stop receiving alerts once the settings are saved.

## Multi-tenancy

`tenant_support` in `config/ai-visibility.php` is `true` by default. When it is on and a tenant can be resolved, all AI Visibility data, settings, keys and engine states belong to that tenant. When no tenant resolves (for example, a panel without tenancy), everything is stored with an empty tenant, which is a normal single-tenant install.

The plugin is built for single-database tenancy: all tenants share the same tables, separated by a `tenant_id` column.

### How the tenant is resolved

In this order:

1. A custom resolver:

   ```php
   // app/Providers/AppServiceProvider.php, boot()
   use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

   Tenancy::resolveUsing(fn () => auth()->user()?->team_id);
   ```

2. The `tenant()` helper, for example from stancl/tenancy (`getTenantKey()` if available, otherwise `id`).
3. Filament's current panel tenant (`Filament::getTenant()->getKey()`).

Tenant IDs are stored as strings, so integer and UUID keys both work.

When no tenant resolves, queries are **not** filtered. In a multi-tenant app, make sure every request that reaches AI Visibility resolves a tenant.

To turn tenancy off completely:

```php
'tenant_support' => false,
```

### What is scoped to a tenant

| Scoped per tenant | Notes |
|---|---|
| Brands, runs, answers (results), usage and costs, candidates, keyword source connections, economy batches, alert rules, alerts inbox, report schedules | Own `tenant_id` column. |
| Competitors, prompts, keywords, topics | Scoped through their brand. |
| Settings, including limits, budgets, alert channels, AI instructions, "Pause everything" and setup completion | One settings row per tenant. Each tenant runs the setup wizard once. |
| API keys saved in the panel | One key per engine (credential) per tenant. |
| Engine states (paused, degraded, reasons) | A paused engine in one tenant does not affect another. |
| Rate limit and pacing | Per tenant and engine. |
| Monthly spend and budgets | Per tenant (and per brand). |

Not scoped: the scheduler and queue heartbeats (the Health page's system checks are the same for everyone), and the config file. When the queue worker stops, every tenant that has settings gets the alert.

### Filament tenancy

AI Visibility resources do their own tenant scoping. They turn off Filament's ownership-relationship scoping, so your tenant model does not need relationships to AI Visibility models. Filament's tenant is picked up automatically (step 3 above).

### Keys and billing across tenants

Only keys saved in the panel are per tenant. **AI Monitor keys and environment keys (`AI_VISIBILITY_*_KEY`) are shared by every tenant that has not saved its own key.** That means:

- Every such tenant's tracking is billed to your provider account.
- Each tenant has its own rate limit and budget, so the combined traffic on the shared key can exceed the provider's limits and cause rate-limit pauses.

If tenants should bring their own keys, do not set the environment keys, and do not install AI Monitor with keys for these providers (or give each tenant its own key in the panel, which always wins).

### Console commands and jobs

Every queued job stores its tenant ID and runs as that tenant.

Scheduled and manual commands act on all tenants:

| Command | Tenant behaviour |
|---|---|
| `ai-visibility:run --due` | Every tenant's active brands, each in its own tenant context. |
| `ai-visibility:run --brand=ID` | That brand, in its tenant. |
| `ai-visibility:discover`, `ai-visibility:redetect`, `ai-visibility:alerts` | Every tenant's brands (or `--brand=ID`). |
| `ai-visibility:sync-keywords` | Every tenant's due connections (or `--brand=ID`, `--all`). |
| `ai-visibility:send-reports`, `ai-visibility:poll-batches`, `ai-visibility:sweep-runs` | Every tenant's rows. |
| `ai-visibility:probe` | Every tenant with a paused engine or a brand paused for budget. |
| `ai-visibility:health {--tenant=}` | System checks once; engines for every tenant, or only `--tenant`. |
| `ai-visibility:engines {--tenant=}` | Every tenant, or only `--tenant`. Works with `--test` and `--resume=engine`. |

Known tenants are those with saved settings, saved keys or engine state. An unknown `--tenant` value is an error.

```bash
php artisan ai-visibility:health --tenant=42
php artisan ai-visibility:engines --tenant=42 --resume=openai
```

Listeners for AI Visibility events receive the tenant in the payload (see [Extending](extending.md#events)). If you queue your own listener, run it as that tenant with `Tenancy::as()`.

### Running code as a tenant

```php
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

$brands = Tenancy::as($team->id, fn () => Brand::query()->get());
```

`Tenancy::as()` overrides the resolver for the callback and restores it afterwards. `Tenancy::currentId()` returns the current tenant ID (or `null`). Use `null` for a single-tenant install.
