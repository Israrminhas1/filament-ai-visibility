<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Prefix for every AI Visibility table. Change it before running the
    | migrations if it clashes with tables in your application.
    |
    */
    'table_prefix' => 'ai_visibility_',

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, all data is scoped to the current tenant, resolved from a
    | custom resolver (Tenancy::resolveUsing()), the tenant() helper
    | (e.g. stancl/tenancy) or Filament's current panel tenant.
    |
    */
    'tenant_support' => true,

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    */
    'queues' => [
        'connection' => env('AI_VISIBILITY_QUEUE_CONNECTION'),
        'tracking' => env('AI_VISIBILITY_QUEUE', 'default'),
        'analysis' => env('AI_VISIBILITY_QUEUE', 'default'),
        'classification' => env('AI_VISIBILITY_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API keys from the environment
    |--------------------------------------------------------------------------
    |
    | Used only when no key is stored in the panel and AI Monitor is not
    | installed (or has no key for that engine).
    |
    */
    'keys' => [
        'openai' => env('AI_VISIBILITY_OPENAI_KEY'),
        'anthropic' => env('AI_VISIBILITY_ANTHROPIC_KEY'),
        'gemini' => env('AI_VISIBILITY_GEMINI_KEY'),
        'grok' => env('AI_VISIBILITY_GROK_KEY'),
        'perplexity' => env('AI_VISIBILITY_PERPLEXITY_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Engines
    |--------------------------------------------------------------------------
    |
    | Default models per engine. "tracking" answers the tracked prompts and
    | should match what users of that assistant see. "helper" is a cheaper
    | model used for analysis, classification and prompt generation.
    |
    | Model names change often: check each provider's model list.
    |
    */
    'engines' => [
        'openai' => [
            'tracking_model' => 'gpt-5-mini',
            'helper_model' => 'gpt-5-mini',
            'models' => ['gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'],
        ],
        'anthropic' => [
            'tracking_model' => 'claude-sonnet-5',
            'helper_model' => 'claude-haiku-4-5',
            'models' => ['claude-opus-5', 'claude-sonnet-5', 'claude-sonnet-4-6', 'claude-haiku-4-5'],
        ],
        'gemini' => [
            'tracking_model' => 'gemini-2.5-flash',
            'helper_model' => 'gemini-2.5-flash-lite',
            'models' => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'],
        ],
        'grok' => [
            'tracking_model' => 'grok-4',
            'helper_model' => 'grok-4',
            'models' => ['grok-4'],
        ],
        'perplexity' => [
            'tracking_model' => 'sonar',
            'helper_model' => 'sonar',
            'models' => ['sonar', 'sonar-pro'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Helper engine order
    |--------------------------------------------------------------------------
    |
    | When a helper feature (analysis, classification, generation) is set to
    | "auto", the first active engine in this order is used. With a single
    | key, everything runs on that engine.
    |
    */
    'helper_engine_order' => ['openai', 'anthropic', 'gemini', 'grok', 'perplexity'],

    /*
    |--------------------------------------------------------------------------
    | Cost estimates
    |--------------------------------------------------------------------------
    |
    | Rough USD cost of one tracked answer per engine (tokens + web search),
    | used for budget estimates before real usage data exists. Actual costs
    | are measured from token usage once runs happen.
    |
    */
    'estimated_cost_per_result' => [
        'openai' => 0.015,
        'anthropic' => 0.03,
        'gemini' => 0.01,
        'grok' => 0.02,
        'perplexity' => 0.008,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default settings
    |--------------------------------------------------------------------------
    |
    | Defaults for the Settings page. Values saved in the panel override
    | these per tenant, and brands can override some of them again.
    |
    */
    'defaults' => [
        'engines' => [
            'enabled' => [],
            'models' => [],
            'requests_per_minute' => 20,
        ],
        'runs' => [
            'samples' => 1,
            'frequency' => 'weekly',
            'time' => '03:00',
        ],
        'limits' => [
            'max_brands' => null,
            'max_competitors_per_brand' => 20,
            'max_active_prompts_per_brand' => 50,
            'max_keywords_per_brand' => 500,
            'max_runs_per_brand_per_day' => 2,
        ],
        'budget' => [
            'monthly_usd' => null,
            'stop_at_budget' => true,
        ],
        'helpers' => [
            'engine' => 'auto',
            'model' => null,
        ],
        'alerts' => [
            'user_ids' => [],
            'emails' => [],
            'slack_webhook' => null,
            'database' => true,
        ],
        'data' => [
            'keep_answers_days' => 365,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings brands may override
    |--------------------------------------------------------------------------
    */
    'brand_overridable' => [
        'engines.enabled',
        'engines.models',
        'runs.samples',
        'runs.frequency',
        'limits.max_competitors_per_brand',
        'limits.max_active_prompts_per_brand',
        'limits.max_runs_per_brand_per_day',
        'budget.monthly_usd',
    ],

    /*
    |--------------------------------------------------------------------------
    | Health thresholds (minutes)
    |--------------------------------------------------------------------------
    */
    'health' => [
        'scheduler_warning_after' => 5,
        'scheduler_critical_after' => 60,
        'queue_warning_after' => 15,
        'queue_critical_after' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => 60,
        'website_fetch_timeout' => 10,
        'user_agent' => 'Mozilla/5.0 (compatible; FilamentAiVisibility/1.0; +https://github.com/Israrminhas1/filament-ai-visibility)',
    ],
];
