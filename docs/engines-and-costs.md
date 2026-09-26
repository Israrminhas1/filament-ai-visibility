# Engines and costs

- [How an answer is collected](#how-an-answer-is-collected)
- [Engines](#engines)
  - [OpenAI (ChatGPT)](#openai-chatgpt)
  - [Anthropic (Claude)](#anthropic-claude)
  - [Google Gemini](#google-gemini)
  - [xAI (Grok)](#xai-grok)
  - [Perplexity](#perplexity)
  - [Google AI Overviews and Google AI Mode](#google-ai-overviews-and-google-ai-mode)
- [API keys](#api-keys)
- [Helper features](#helper-features)
- [Economy (batch) mode](#economy-batch-mode)
- [How costs are calculated](#how-costs-are-calculated)
- [Estimates](#estimates)
- [Budgets](#budgets)

## How an answer is collected

A run asks every active prompt of a brand on every enabled engine, once per sample. Each answer is one request with the engine's **web search turned on**, so the answer matches what a user of that assistant sees. For each answer AI Visibility stores:

- the answer text,
- whether the brand is mentioned, how often, and its position among tracked brands,
- which competitors are mentioned, with the sentence around each mention,
- every source, in the order the engine gave them (cited sources first where the engine separates them),
- input and output tokens, number of searches, cost and duration.

The brand's **Market** is turned into a country code (see `markets` in [Configuration](configuration.md#markets)) and sent as the search location where the engine supports it.

Every request uses `http.timeout` (60 s). Only tracked answers use web search. Helper calls (analysis, classification, generation) are plain completions without search.

## Engines

| Key | Label | API | Web search | Location sent | Batch (economy) |
|---|---|---|---|---|---|
| `openai` | OpenAI (ChatGPT) | Responses API `POST /v1/responses` | `web_search` tool | Yes (`user_location`) | Yes |
| `anthropic` | Anthropic (Claude) | Messages API `POST /v1/messages` | `web_search_20250305` server tool | Yes (`user_location`) | Yes |
| `gemini` | Google Gemini | `generateContent` (v1beta) | Grounding with Google Search | No | No |
| `grok` | xAI (Grok) | xAI Responses API `POST /v1/responses` | `web_search` tool | No | No |
| `perplexity` | Perplexity | Agent API `POST /v1/agent` | `web_search` tool | Yes (`user_location`) | No |
| `google_ai_overview` | Google AI Overviews | SerpAPI (`engine=google`) | Google search | Yes (`gl`) | No |
| `google_ai_mode` | Google AI Mode | SerpAPI (`engine=google_ai_mode`) | Google AI Mode | Yes (`gl`) | No |

Default models are in `config/ai-visibility.php` under `engines` and can be changed per tenant in **Settings → Engines & API keys**, or per brand. The model pickers only suggest models that support the engine's web search and return sources. You can type any other model. If the provider says the model does not exist or cannot search the web, the engine pauses with **Model unavailable** instead of failing every answer.

### OpenAI (ChatGPT)

- Default model: `gpt-6-luna` (tracking and helper).
- Suggestions: `gpt-6-astra`, `gpt-6-sol`, `gpt-6-luna`, `gpt-5.5`, `gpt-5.4`, `gpt-5-mini`.
- Sources: `url_citation` annotations in the answer.
- Searches: one per `web_search_call` in the output.
- Key test: `GET /v1/models` (free). The key's models are listed.
- AI Monitor provider name: `openai`.

### Anthropic (Claude)

- Default model: `claude-sonnet-5` (tracking and helper).
- Suggestions: `claude-fable-5-1`, `claude-opus-5-5`, `claude-sonnet-5`, `claude-haiku-4-5`.
- Uses the basic web search tool (`web_search_20250305`) with `max_uses` = `tracking.max_searches` (5). It works on every model and in batches, and returns every search result.
- A long search can stop with `pause_turn`. The conversation is then continued, up to three requests in total. Tokens and searches of all turns are added up. This is why an answer job can take a few minutes.
- `max_tokens` = `tracking.max_output_tokens` (4096).
- Sources: citations in the answer first, then every search result the model read.
- Searches: `usage.server_tool_use.web_search_requests`.
- Key test: `GET /v1/models` (free).
- AI Monitor provider names: `anthropic`, `claude`.

### Google Gemini

- Default models: `gemini-3.8-flash` (tracking), `gemini-3.5-flash-lite` (helper).
- Suggestions: `gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-pro-preview`, `gemini-2.5-flash`, `gemini-2.5-pro`. The 2.5 models only work for projects that already used them.
- Uses the `google_search` tool (grounding). The key is sent in a header, never in the URL.
- Sources: grounding chunks. Google redirect links are replaced by the source's domain when the title holds it.
- Searches: Gemini 3 models count every search query; Gemini 2.5 counts one per grounded prompt. Output tokens include thinking tokens.
- Key test: `GET /models` (free).
- AI Monitor provider names: `gemini`, `google`.

### xAI (Grok)

- Default models: `grok-4.7` (tracking), `grok-4.3` (helper).
- Suggestions: `grok-4.7`, `grok-4.6`, `grok-4.3`.
- Sources: `url_citation` annotations and the top-level `citations` list.
- Searches: `web_search_call` items; otherwise 1 if the response reports sources used.
- Key test: `GET /v1/models` (free).
- AI Monitor provider names: `grok`, `xai`.

### Perplexity

- Default model: `perplexity/sonar` (what Perplexity itself answers with).
- Uses the Agent API, which replaced Sonar Chat Completions. Model names without a `provider/` prefix get `perplexity/` added. The old names `sonar-pro`, `sonar-reasoning-pro` and `sonar-deep-research` are sent as the Agent API presets `low`, `medium` and `high` (presets bring their own tools, so no location is sent).
- `max_output_tokens` = `tracking.max_output_tokens`.
- Sources: citations first, then every item of the `search_results` output.
- Searches: `usage.tool_calls_details.web_search.invocation`, or the number of search result blocks.
- Key test: a tiny request without web search (costs a fraction of a cent). There is no model list.
- AI Monitor provider name: `perplexity`.

### Google AI Overviews and Google AI Mode

Google's own AI answers, read through [SerpAPI](https://serpapi.com). No model choice (the "model" is the engine key).

- **Both engines share one SerpAPI key** (credential `serpapi`). Settings shows the key field once, under Google AI Overviews. Removing it stops both engines.
- AI Overviews: one Google search. Some overviews load separately; the second request is made straight away, so that answer costs **two** SerpAPI searches.
- AI Mode: one SerpAPI search per answer.
- The search uses `gl` (country from the brand's market) and `hl=en`.
- When Google shows no AI answer for a search, the answer is stored as "Google did not show an AI answer for this search." It counts as an answer without mentions, not as a failure.
- Sources: SerpAPI `references`.
- Tokens: none. Cost is only the search fee (`pricing.search_fee.google_ai_overview` / `google_ai_mode`, default $0.015; set it to your SerpAPI plan's price per search).
- Key test: `GET https://serpapi.com/account.json` (free). Shows the searches left. Zero searches left pauses the engine for credits.
- They cannot do helper work. Helper features always use one of the other engines.
- The key is sent as a URL parameter by SerpAPI's design. Error messages have it removed before they are stored.

## API keys

For each engine, the key is taken from the first of:

1. **Saved in the panel** (Settings → Engines & API keys, or the setup wizard). Encrypted with `APP_KEY`, per tenant, never sent back to the browser. **Remove saved key** deletes it.
2. **AI Monitor**, if installed and it has a key under one of the engine's provider names (listed above).
3. **Environment**: `AI_VISIBILITY_OPENAI_KEY`, `AI_VISIBILITY_ANTHROPIC_KEY`, `AI_VISIBILITY_GEMINI_KEY`, `AI_VISIBILITY_GROK_KEY`, `AI_VISIBILITY_PERPLEXITY_KEY`, `AI_VISIBILITY_SERPAPI_KEY`.

The Settings page and `php artisan ai-visibility:engines` show where each key comes from (`panel`, `ai-monitor` or `env`).

If `APP_KEY` changes, saved keys can no longer be decrypted. They are treated as missing (the engine pauses with **No API key**) and a warning is logged once. Save the keys again.

## Helper features

Answer analysis, name extraction, competitor classification and suggestions, prompt generation, prompt quality review and topic grouping use a **helper** engine. It is chosen in **Settings → AI helpers**:

- **Automatic** (default): the first engine in `helper_engine_order` (OpenAI, Anthropic, Gemini, Grok, Perplexity, then custom engines) that has a key and is not paused, using its `helper_model`. The engine does not need to be switched on for tracking. If that engine pauses, the next one is used.
- **A pinned engine** (and optional model): only that engine. If it is paused, helper steps are skipped and retried later.

Helper calls are recorded with their own purpose (`analysis`, `classification`, `generation`) and count towards spend and budgets. They are blocked by "Pause everything" and by a used-up budget.

## Economy (batch) mode

Turn on **Settings → Engines & API keys → Economy mode for scheduled runs**.

- Applies to **scheduled** runs on engines that support batches: OpenAI and Anthropic. Manual runs (**Run now**) and other engines are always real time.
- OpenAI: the requests are uploaded as a JSONL file and sent to the Batch API for `/v1/responses` with a 24-hour window. Anthropic: the Message Batches API. Each answer in an Anthropic batch is a single request, so a search that would continue (`pause_turn`) keeps what it had.
- Token cost gets `pricing.batch_discount` off (default 50%). Search fees are not discounted.
- One `SubmitBatchJob` per engine and up to `economy.max_batch_size` (1000) answers.
- `ai-visibility:poll-batches` (every 5 minutes) checks batches and stores finished answers. The run page shows how many answers are still waiting.
- Nothing is lost:
  - A failed, cancelled or expired batch, an answer the provider rejected, an empty answer, or a batch still unfinished after `economy.give_up_after_hours` (26) is asked again in real time.
  - A bad key or empty credit balance pauses the engine, as it does in real time.
  - If submitting fails before the provider accepted the batch, the answers are asked in real time.
  - A batch is never submitted twice for the same answers.

## How costs are calculated

Every call records its tokens, searches and cost in the usage table (and in AI Monitor if installed).

```
cost = token cost × (1 − batch discount, for batch answers) + searches × search fee
```

**Token cost** = `input tokens × input price + output tokens × output price`, prices per 1M tokens:

1. AI Monitor's pricing table, if installed and it has a price for the model.
2. `pricing.models` in the config: exact model name, then the longest base name followed by `-` (so `gpt-5-mini-2025-08-07` uses `gpt-5-mini`). Case-insensitive.
3. `pricing.fallback.{engine}`.

**Search fee** per search: the longest matching prefix in `pricing.search_fee_models` (e.g. `gemini-2.5` → $0.035), else `pricing.search_fee.{engine}`.

The bundled prices are estimates. Check them against each provider's pricing page and edit your published config. To price a new model, add it to `pricing.models`:

```php
'pricing' => [
    'models' => [
        // ...
        'gpt-6-luna' => [0.10, 0.50],      // [input, output] USD per 1M tokens
        'my-new-model' => [1.00, 4.00],
    ],
],
```

A run's cost is the sum of its answers. Spend for budgets is the sum of all recorded calls this calendar month (tracking and helper calls).

## Estimates

Before a run starts (the **Run now** confirmation, the setup wizard, and the budget check) AI Visibility estimates:

```
run estimate = Σ over engines ( active prompts × samples × cost per answer )
monthly estimate = run estimate × runs per month   (daily = 30, weekly = 4.33, manual = 0)
```

**Cost per answer** is learned: the average cost of the tenant's last 50 successful answers for the same engine and model (at least 5 needed), else for the same engine, else `estimated_cost_per_result.{engine}` from the config ($0.02 for an engine not listed).

## Budgets

Two budgets, both optional and both monthly (calendar month):

| Budget | Set in | Applies to |
|---|---|---|
| Tenant budget | Settings → Budget → Monthly budget (USD) | All spending of the tenant (tracking and helpers) |
| Brand budget | Brand → Settings tab → Monthly budget for this brand | That brand's spending |

With **Pause all engines when the budget is reached** on (`budget.stop_at_budget`, default):

1. **Before a run**: the remaining budget (the smaller of tenant and brand) is the budget minus this month's spend minus the expected cost of runs still in progress. A run whose estimate is larger is refused with the reason. Scheduled runs are also skipped while the tenant budget is used up.
2. **Before each answer**: if actual spend has reached a budget, the answer is skipped (`budget`) without calling the engine.
3. **After each answer**: when the tenant budget is used up, every enabled engine pauses with **Budget reached** (engines already paused for another reason keep that reason). When a brand budget is used up, the brand is paused and an alert is sent.
4. **Resuming**: `ai-visibility:probe` (every 5 minutes) resumes engines and brands once there is budget again: a new month, or a raised budget.

With it off, budgets are shown but nothing is stopped.

Alert rules can also warn when spend reaches a percentage of the budget ("Spend reaches part of the budget").
