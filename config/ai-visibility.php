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
    | Require setup
    |--------------------------------------------------------------------------
    |
    | Runs only start once the setup wizard is complete. Set to false when
    | everything is configured in code (with ->withoutSetupWizard()).
    |
    */
    'require_setup' => true,

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
            'tracking_model' => 'grok-4.7',
            'helper_model' => 'grok-4.7',
            'models' => ['grok-4.7', 'grok-4'],
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
    | Markets
    |--------------------------------------------------------------------------
    |
    | Maps a brand's market to a country code so engines search as a user in
    | that country would. A two-letter code (e.g. "GB") also works directly.
    |
    */
    'markets' => [
        'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB', 'england' => 'GB',
        'united states' => 'US', 'usa' => 'US', 'us' => 'US', 'america' => 'US',
        'canada' => 'CA', 'australia' => 'AU', 'new zealand' => 'NZ', 'ireland' => 'IE',
        'germany' => 'DE', 'france' => 'FR', 'spain' => 'ES', 'italy' => 'IT', 'netherlands' => 'NL',
        'belgium' => 'BE', 'sweden' => 'SE', 'norway' => 'NO', 'denmark' => 'DK', 'finland' => 'FI',
        'poland' => 'PL', 'portugal' => 'PT', 'switzerland' => 'CH', 'austria' => 'AT',
        'india' => 'IN', 'pakistan' => 'PK', 'united arab emirates' => 'AE', 'uae' => 'AE', 'saudi arabia' => 'SA',
        'singapore' => 'SG', 'japan' => 'JP', 'south korea' => 'KR', 'china' => 'CN', 'hong kong' => 'HK',
        'brazil' => 'BR', 'mexico' => 'MX', 'argentina' => 'AR', 'south africa' => 'ZA', 'nigeria' => 'NG',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | Used to calculate spend when AI Monitor is not installed (or has no
    | price for a model). USD per 1M tokens, plus a fee per web search.
    | Models are matched exactly, then by prefix ("gpt-5-mini-2025..."
    | uses "gpt-5-mini"). These are estimates: check each provider's
    | pricing page and adjust.
    |
    */
    'pricing' => [
        'models' => [
            'gpt-5' => [1.25, 10.00],
            'gpt-5-mini' => [0.25, 2.00],
            'gpt-5-nano' => [0.05, 0.40],
            'gpt-4.1' => [2.00, 8.00],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4o' => [2.50, 10.00],
            'gpt-4o-mini' => [0.15, 0.60],
            'claude-opus-5-5' => [4.00, 20.00],
            'claude-opus-5' => [5.00, 25.00],
            'claude-sonnet-5' => [2.00, 10.00],
            'claude-sonnet-4-6' => [3.00, 15.00],
            'claude-haiku-4-5' => [1.00, 5.00],
            'gemini-2.5-pro' => [1.25, 10.00],
            'gemini-2.5-flash' => [0.30, 2.50],
            'gemini-2.5-flash-lite' => [0.10, 0.40],
            'grok-4' => [3.00, 15.00],
            'sonar-pro' => [3.00, 15.00],
            'sonar' => [1.00, 1.00],
        ],
        // Per engine, used when a model has no price above.
        'fallback' => [
            'openai' => [1.25, 10.00],
            'anthropic' => [3.00, 15.00],
            'gemini' => [0.30, 2.50],
            'grok' => [3.00, 15.00],
            'perplexity' => [1.00, 1.00],
        ],
        // USD per web search / grounded request.
        'search_fee' => [
            'openai' => 0.01,
            'anthropic' => 0.01,
            'gemini' => 0.035,
            'grok' => 0.01,
            'perplexity' => 0.008,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source categories
    |--------------------------------------------------------------------------
    |
    | Cited domains are grouped into categories for the Sources report. Brand
    | and competitor domains are always "own" / "competitor". Add your own
    | domains to these lists; subdomains match automatically.
    |
    */
    'source_categories' => [
        'review_comparison' => ['g2.com', 'capterra.com', 'trustpilot.com', 'getapp.com', 'softwareadvice.com', 'trustradius.com', 'gartner.com', 'clutch.co', 'yelp.com', 'tripadvisor.com', 'consumerreports.org', 'which.co.uk', 'pcmag.com', 'techradar.com', 'tomsguide.com', 'wirecutter.com', 'rtings.com', 'producthunt.com', 'sitejabber.com', 'glassdoor.com'],
        'forum_community' => ['reddit.com', 'quora.com', 'stackexchange.com', 'stackoverflow.com', 'news.ycombinator.com', 'discord.com', 'community.spiceworks.com', 'mumsnet.com', 'forums.whirlpool.net.au'],
        'social' => ['youtube.com', 'linkedin.com', 'x.com', 'twitter.com', 'facebook.com', 'instagram.com', 'tiktok.com', 'medium.com', 'substack.com', 'pinterest.com'],
        'marketplace' => ['amazon.com', 'amazon.co.uk', 'ebay.com', 'ebay.co.uk', 'etsy.com', 'walmart.com', 'aliexpress.com', 'bestbuy.com', 'argos.co.uk', 'apps.apple.com', 'play.google.com', 'appsumo.com', 'shopify.com'],
        'wiki_reference' => ['wikipedia.org', 'wikihow.com', 'britannica.com', 'investopedia.com', 'merriam-webster.com', 'dictionary.com'],
        'media_publisher' => ['forbes.com', 'nytimes.com', 'theguardian.com', 'bbc.co.uk', 'bbc.com', 'cnn.com', 'reuters.com', 'bloomberg.com', 'techcrunch.com', 'theverge.com', 'wired.com', 'businessinsider.com', 'cnet.com', 'zdnet.com', 'hubspot.com', 'entrepreneur.com', 'inc.com', 'fastcompany.com'],
        'government_education' => ['.gov', '.gov.uk', '.edu', '.ac.uk', '.gov.au', '.edu.au', '.europa.eu'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking requests
    |--------------------------------------------------------------------------
    */
    'tracking' => [
        // Max answer length requested from engines that need a limit.
        'max_output_tokens' => 4096,
        // Max web searches per answer, where the engine supports a limit.
        'max_searches' => 5,
        // Seconds a job may keep retrying (rate limits, outages) before giving up.
        'retry_for_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reliability
    |--------------------------------------------------------------------------
    */
    'reliability' => [
        // Consecutive failures before the circuit breaker pauses an engine.
        'failure_threshold' => 5,
        // First pause after an outage, doubled each time up to the max (minutes).
        'outage_pause_minutes' => 15,
        'outage_pause_max_minutes' => 240,
        // How often an engine paused for credits is re-checked (minutes).
        'credits_probe_minutes' => 360,
        // Consecutive rate-limit responses before pausing; and how long a degraded engine stays slowed (minutes).
        'rate_limit_threshold' => 5,
        'degraded_minutes' => 30,
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
