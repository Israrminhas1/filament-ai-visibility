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
    |
    | Out of the box everything runs on your app's default queue, so a single
    | `php artisan queue:work` is enough. For real volumes, give tracking its
    | own queue and several worker processes: each answer waits 10–60 seconds
    | on a web search, so one worker handles only a few answers a minute.
    | See docs/queues-and-scheduler.md.
    |
    */
    'queues' => [
        // Queue connection (null = your app's default).
        'connection' => env('AI_VISIBILITY_QUEUE_CONNECTION'),
        // Answering prompts, and economy-mode batch submissions: high volume, slow calls.
        'tracking' => env('AI_VISIBILITY_QUEUE_TRACKING', env('AI_VISIBILITY_QUEUE', 'default')),
        // Alert rules after each run: short jobs.
        'analysis' => env('AI_VISIBILITY_QUEUE_ANALYSIS', env('AI_VISIBILITY_QUEUE', 'default')),
        // Competitor discovery and classification, re-checking past answers: long jobs.
        'classification' => env('AI_VISIBILITY_QUEUE_CLASSIFICATION', env('AI_VISIBILITY_QUEUE', 'default')),
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
        // Used by the Google AI Overviews and Google AI Mode engines.
        'serpapi' => env('AI_VISIBILITY_SERPAPI_KEY'),
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
        // `models` lists only models that support the engine's web search and return sources.
        'openai' => [
            'tracking_model' => 'gpt-6-luna',
            'helper_model' => 'gpt-6-luna',
            'models' => ['gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-5.5', 'gpt-5.4', 'gpt-5-mini'],
        ],
        'anthropic' => [
            'tracking_model' => 'claude-sonnet-5',
            'helper_model' => 'claude-sonnet-5',
            'models' => ['claude-fable-5-1', 'claude-opus-5-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
        ],
        'gemini' => [
            'tracking_model' => 'gemini-3.8-flash',
            'helper_model' => 'gemini-3.5-flash-lite',
            // 2.5 models are only available to projects that already used them.
            'models' => ['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-pro-preview', 'gemini-2.5-flash', 'gemini-2.5-pro'],
        ],
        'grok' => [
            'tracking_model' => 'grok-4.7',
            'helper_model' => 'grok-4.3',
            'models' => ['grok-4.7', 'grok-4.6', 'grok-4.3'],
        ],
        // Perplexity's Agent API. "perplexity/sonar" is what Perplexity itself answers with.
        'perplexity' => [
            'tracking_model' => 'perplexity/sonar',
            'helper_model' => 'perplexity/sonar',
            'models' => ['perplexity/sonar'],
        ],
        // Google's own AI answers, read through SerpAPI (no model choice).
        'google_ai_overview' => [
            'tracking_model' => 'google_ai_overview',
            'helper_model' => 'google_ai_overview',
            'models' => ['google_ai_overview'],
        ],
        'google_ai_mode' => [
            'tracking_model' => 'google_ai_mode',
            'helper_model' => 'google_ai_mode',
            'models' => ['google_ai_mode'],
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
        'gemini' => 0.03,
        'grok' => 0.02,
        'perplexity' => 0.005,
        'google_ai_overview' => 0.015,
        'google_ai_mode' => 0.015,
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
            'gpt-6-astra' => [10.00, 50.00],
            'gpt-6-sol' => [2.00, 10.00],
            'gpt-6-luna' => [0.10, 0.50],
            'gpt-5.5' => [5.00, 30.00],
            'gpt-5.4' => [2.50, 15.00],
            'gpt-5' => [1.25, 10.00],
            'gpt-5-mini' => [0.25, 2.00],
            'gpt-5-nano' => [0.05, 0.40],
            'gpt-4.1' => [2.00, 8.00],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4o' => [2.50, 10.00],
            'gpt-4o-mini' => [0.15, 0.60],
            'claude-fable-5-1' => [10.00, 50.00],
            'claude-opus-5-5' => [4.00, 20.00],
            'claude-opus-5' => [5.00, 25.00],
            'claude-sonnet-5' => [2.00, 10.00],
            'claude-sonnet-4-6' => [3.00, 15.00],
            'claude-haiku-4-5' => [1.00, 5.00],
            'gemini-3.8-flash' => [0.75, 3.75],
            'gemini-3.7-flash' => [0.75, 3.75],
            'gemini-3.5-flash' => [1.50, 9.00],
            'gemini-3.5-flash-lite' => [0.30, 2.50],
            'gemini-3.1-pro-preview' => [2.00, 12.00],
            'gemini-2.5-pro' => [1.25, 10.00],
            'gemini-2.5-flash' => [0.30, 2.50],
            'gemini-2.5-flash-lite' => [0.10, 0.40],
            'grok-4.7' => [2.00, 6.00],
            'grok-4.6' => [2.00, 6.00],
            'grok-4.3' => [1.25, 2.50],
            'grok-4' => [3.00, 15.00],
            'perplexity/sonar' => [0.25, 2.50],
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
            'google_ai_overview' => [0.0, 0.0],
            'google_ai_mode' => [0.0, 0.0],
        ],
        // USD per web search / grounded request.
        'search_fee' => [
            'openai' => 0.01,
            'anthropic' => 0.01,
            // Gemini 3 bills each search query; 2.5 models bill per grounded prompt (see search_fee_models).
            'gemini' => 0.014,
            'grok' => 0.01,
            'perplexity' => 0.0025,
            // Per SerpAPI search; depends on your SerpAPI plan.
            'google_ai_overview' => 0.015,
            'google_ai_mode' => 0.015,
        ],
        // Per-model search fees that differ from the engine's; prefixes match ("gemini-2.5" covers all 2.5 models).
        'search_fee_models' => [
            'gemini-2.5' => 0.035,
        ],
        // Token discount for economy (batch) mode.
        'batch_discount' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Economy mode
    |--------------------------------------------------------------------------
    |
    | When turned on in Settings, scheduled runs on OpenAI and Claude use the
    | providers' batch APIs: about half the token cost, answers within 24
    | hours. Manual runs always answer in real time. Anything a batch cannot
    | answer is retried in real time after `give_up_after_hours`.
    |
    */
    'economy' => [
        'max_batch_size' => 1000,
        'give_up_after_hours' => 26,
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
    | Competitor discovery
    |--------------------------------------------------------------------------
    */
    'discovery' => [
        // Answers from this many days are used to find and score candidates.
        'window_days' => 90,
        // Fetched website evidence is reused for this many days.
        'evidence_days' => 30,
        // Candidates per classification request, and answers per name-extraction request.
        'classification_batch' => 10,
        'extraction_batch' => 8,
        // Answers per analysis request.
        'analysis_batch' => 5,
        // Failed attempts before an answer's analysis is marked failed.
        'analysis_attempts' => 3,
        // Days before retrying a candidate the AI could not label.
        'retry_failed_days' => 7,
        // How candidates are scored (0–100). Weights are relative.
        'weights' => [
            'answers' => 0.35,
            'prompts' => 0.25,
            'engines' => 0.20,
            'position' => 0.10,
            'recency' => 0.10,
        ],
        // Never treated as competitors (search engines, link shorteners…).
        // Platforms that are never competitors (reddit.com, youtube.com, g2.com…). Null uses
        // the built-in list; set an array to replace it.
        'platform_domains' => null,
        'ignored_domains' => [
            'google.com', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com', 'yandex.com',
            'googleusercontent.com', 'gstatic.com', 'vertexaisearch.cloud.google.com',
            't.co', 'bit.ly', 'goo.gl', 'archive.org', 'web.archive.org',
        ],
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
        // Hours without progress before a run's unanswered results are failed and the run is closed.
        'stale_run_hours' => 6,
        // Longest single queue delay in seconds (SQS allows 900); longer waits are split into steps.
        'max_queue_delay' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        // At most one "engine paused" alert per engine and reason in this many hours (0 = no limit).
        'engine_alert_throttle_hours' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Import limits
    |--------------------------------------------------------------------------
    |
    | Per-brand limits (brands, prompts, keywords…) are set in Settings.
    |
    */
    'limits' => [
        // Most rows read from one prompt CSV/paste import (0 = no cap).
        'max_import_rows' => 5000,
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
            'economy' => false,
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
        'discovery' => [
            'enabled' => true,
            'extract_names' => true,
            'classify' => true,
            'top_n' => 25,
            'auto_accept' => false,
            'reclassify_days' => 90,
            'ignored_domains' => [],
            // Platforms (e.g. amazon.com) that are real competitors for this brand.
            'allow_platforms' => [],
        ],
        // Custom AI instructions; empty means the built-in default.
        'analysis' => [
            'enabled' => true,
        ],
        'keywords' => [
            'sync_days' => 30,
        ],
        'generation' => [
            'count' => 10,
            'intents' => ['discovery', 'comparison', 'alternatives', 'problem'],
            'persona' => null,
            'ai_review' => true,
            'min_quality' => 4,
            'prompts_per_keyword' => 2,
        ],
        'instructions' => [
            'generation' => null,
            'quality_review' => null,
            'topics' => null,
            'analysis' => null,
            'classification' => null,
            'extraction' => null,
            'suggest_competitors' => null,
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
        'discovery.allow_platforms',
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
