# Plugin options

Configure the plugin where you register it in your panel provider. Every option returns the plugin, so you can chain them.

```php
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

$panel->plugin(
    AiVisibilityPlugin::make()
        ->navigationGroup('Marketing')
        ->navigationGroups()
        ->navigationSort(10)
        ->authorizeUsing(fn ($user) => $user->can('view-ai-visibility'))
        ->canManageSettings(fn ($user) => $user->is_admin)
        ->scheduledReports(false)
);
```

Options are per panel. If you register the plugin on two panels, configure each one.

- [Navigation](#navigation)
- [Screens](#screens)
- [Permissions](#permissions)
- [Setup wizard](#setup-wizard)
- [Engines and keyword sources](#engines-and-keyword-sources)
- [Static helpers](#static-helpers)

## Navigation

### `navigationGroup(?string $group): static`

Puts every AI Visibility screen in one navigation group with this name. It also turns the three-group split off. Pass `null` for no group.

```php
AiVisibilityPlugin::make()->navigationGroup('Marketing');
AiVisibilityPlugin::make()->navigationGroup(null); // no group
```

### `navigationGroups(bool $condition = true): static`

Splits the screens into three groups (on by default):

| Group | Screens |
|---|---|
| `AI Visibility` | Overview, Sources, Competitors, Head-to-head, Opportunities, Topics |
| `AI Visibility · Tracking` | Brands, Discovered, Prompts, Keywords, Keyword sources, Runs, Answers |
| `AI Visibility · Admin` | Setup (shown until setup is complete), Alerts, Alert rules, Scheduled reports, Settings, Health |

The group names follow `navigationGroup()`. Call `navigationGroups()` **after** `navigationGroup()` to keep the split with your own name:

```php
// "Marketing", "Marketing · Tracking", "Marketing · Admin"
AiVisibilityPlugin::make()
    ->navigationGroup('Marketing')
    ->navigationGroups();

// one "AI Visibility" group
AiVisibilityPlugin::make()->navigationGroups(false);
```

The split needs a group name. With `navigationGroup(null)` there is no group at all.

### `navigationSort(int $sort): static`

Added to each screen's own position, so you can move the whole block. Default `0`. Screens use positions 0–95 internally (Overview 1, Brands 10, Settings 90, Health 95).

```php
AiVisibilityPlugin::make()->navigationSort(100);
```

### Getters

| Method | Returns |
|---|---|
| `getNavigationGroup(): ?string` | The base group name. |
| `hasNavigationGroups(): bool` | Whether the three-group split is active. |
| `getNavigationGroupFor(string $area): ?string` | The group for `'reports'`, `'tracking'` or `'admin'`. |
| `getNavigationSort(): int` | The sort offset. |

## Screens

Each method takes `bool $condition = true`. Pass `false` to remove the screen from the panel (no navigation item and no route).

| Method | Screens it controls |
|---|---|
| `overview()` | Overview (the dashboard at `/{panel}/ai-visibility`) |
| `sourcesReport()` | Sources report |
| `competitorReports()` | Competitors, Head-to-head and Opportunities reports |
| `topicsReport()` | Topics report |
| `brands()` | Brands (with their competitors, prompts, topics and keywords tabs) |
| `discovered()` | Discovered (competitor candidates) |
| `prompts()` | Prompts |
| `keywords()` | Keywords |
| `keywordSourcesScreen()` | Keyword sources |
| `runs()` | Runs |
| `answers()` | Answers |
| `alerts()` | Alerts inbox **and** Alert rules |
| `scheduledReports()` | Scheduled reports |
| `settingsPage()` | Settings |
| `healthPage()` | Health |

```php
AiVisibilityPlugin::make()
    ->competitorReports(false)
    ->scheduledReports(false)
    ->keywordSourcesScreen(false);
```

Notes:

- Hidden screens stop links to them from appearing (for example, "Open Health" in alerts is left out).
- Background work is not affected. Hiding **Alert rules** does not stop existing rules from being checked. Hiding **Settings** leaves the stored settings in force.
- If you hide **Settings** or **Brands**, you need another way to manage those (the setup wizard, or code).

## Permissions

Full details: [Permissions and tenancy](permissions-and-tenancy.md).

### `authorizeUsing(?Closure $callback): static`

Who can open AI Visibility at all. The closure receives the logged-in user and returns a bool. Without it, everyone who can use the panel can use AI Visibility. It is never called for guests (a guest is not authorised).

```php
AiVisibilityPlugin::make()
    ->authorizeUsing(fn ($user) => $user->hasRole(['marketing', 'admin']));
```

### `canManageSettings(Closure|bool $condition = true): static`

Who can change settings, API keys, budgets, keyword sources, alert rules, scheduled reports, per-brand overrides, engine pauses, and run setup. Takes a closure (receives the user) or a bool. Default `true`. A user must also pass `authorizeUsing()`.

```php
AiVisibilityPlugin::make()->canManageSettings(fn ($user) => $user->is_admin);
AiVisibilityPlugin::make()->canManageSettings(false); // nobody manages settings in this panel
```

### `alertRecipientsQuery(?Closure $callback): static`

Limits which users the "Panel users who receive alerts" picker offers. The closure receives the users query (from `auth.providers.users.model`) and the current Filament tenant (or `null`). Return the query.

```php
AiVisibilityPlugin::make()
    ->alertRecipientsQuery(fn ($query, $tenant) => $query->where('team_id', $tenant?->getKey()));
```

Without it: with Filament tenancy, the tenant's `users()` or `members()` relationship is used, or only the current user if there is no such relationship. Without tenancy, the list starts with the current user and you search for others.

### Checks

| Method | Returns |
|---|---|
| `isAuthorized(): bool` | Whether the current user passes `authorizeUsing()`. `false` without a logged-in user when a callback is set. |
| `canManage(): bool` | Whether the current user passes both checks. |
| `static userCanManage(): bool` | `canManage()` on the current panel; `true` when the plugin is not on the current panel (console, jobs). |
| `getAlertRecipientsQuery(): ?Closure` | The closure given to `alertRecipientsQuery()`. |

## Setup wizard

### `withoutSetupWizard(bool $condition = true): static`

Removes the setup wizard page and the redirect to it. Use it when you configure engines, keys and settings in code.

```php
AiVisibilityPlugin::make()->withoutSetupWizard();
```

Runs still need setup to be complete unless you also set `'require_setup' => false` in `config/ai-visibility.php`. See [Installation](installation.md#skipping-the-wizard-configuration-in-code).

`hasSetupWizard(): bool` returns whether the wizard is on.

## Engines and keyword sources

### `engine(string|Engine $engine): static`

Registers an extra AI engine: a class name or an instance implementing `IsrarMinhas\FilamentAiVisibility\Engines\Contracts\Engine`.

```php
AiVisibilityPlugin::make()->engine(\App\AiVisibility\AcmeEngine::class);
```

### `withoutEngine(string $key): static`

Removes a built-in engine by its key: `openai`, `anthropic`, `gemini`, `grok`, `perplexity`, `google_ai_overview`, `google_ai_mode`.

```php
AiVisibilityPlugin::make()
    ->withoutEngine('grok')
    ->withoutEngine('google_ai_mode');
```

### `keywordSource(string|KeywordSource $source): static`

Registers an extra keyword source implementing `IsrarMinhas\FilamentAiVisibility\Keywords\Contracts\KeywordSource`.

```php
AiVisibilityPlugin::make()->keywordSource(\App\AiVisibility\UrlCsvKeywords::class);
```

**Important:** answers, batches and keyword syncs run in queue workers and scheduled commands. These three options apply wherever your panel is built, which a queue worker may skip (for example with `php artisan route:cache`). For anything jobs use, register in a service provider instead. The static methods work in every process:

```php
// app/Providers/AppServiceProvider.php
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;

public function register(): void
{
    AiVisibilityPlugin::registerEngine(\App\AiVisibility\AcmeEngine::class);
    AiVisibilityPlugin::removeEngine('grok');
    AiVisibilityPlugin::registerKeywordSource(\App\AiVisibility\UrlCsvKeywords::class);
}
```

See [Extending](extending.md) for complete engine and keyword source classes.

## Static helpers

| Method | Use |
|---|---|
| `AiVisibilityPlugin::make(): static` | New plugin instance (resolved from the container). |
| `AiVisibilityPlugin::get(): static` | The plugin on the current panel. Throws if it is not registered there. |
| `AiVisibilityPlugin::current(): ?static` | The plugin on the current (or default) panel, or `null`. |
| `AiVisibilityPlugin::pageUrl(string $class, string $page = 'index', array $parameters = []): ?string` | URL of an AI Visibility page or resource on the current panel, or `null` if it is not registered there. |
| `getId(): string` | `'ai-visibility'`. |

```php
use IsrarMinhas\FilamentAiVisibility\Filament\Pages\Health;

$url = AiVisibilityPlugin::pageUrl(Health::class); // null if the Health page is hidden
```
