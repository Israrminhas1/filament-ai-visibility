# Queues and scheduler

AI Visibility runs on your app's own queue and scheduler. It does not start its own processes. This page explains what it queues, what it schedules, and how to run workers so that runs finish in reasonable time.

- [How it uses your queue](#how-it-uses-your-queue)
- [Queue settings](#queue-settings)
- [Jobs](#jobs)
- [Scheduled tasks](#scheduled-tasks)
- [retry_after and worker timeout (required)](#retry_after-and-worker-timeout-required)
- [Throughput: how many workers you need](#throughput-how-many-workers-you-need)
- [Ready-made setups](#ready-made-setups)
  - [a) Small app: one worker](#a-small-app-one-worker)
  - [b) Recommended: separate queues](#b-recommended-separate-queues)
  - [c) A dedicated Redis connection](#c-a-dedicated-redis-connection)
- [Supervisor](#supervisor)
- [Laravel Horizon](#laravel-horizon)
- [Local development (Windows / Laragon)](#local-development-windows--laragon)
- [Cache, rate limits and multiple servers](#cache-rate-limits-and-multiple-servers)
- [What the Health page shows](#what-the-health-page-shows)

## How it uses your queue

Every AI call that can be slow runs in a queued job:

- Answering tracked prompts (one job per prompt × engine × sample).
- Sending economy-mode batches.
- Answer analysis, competitor discovery and classification.
- Re-checking stored answers after a brand's names change.
- Checking alert rules after each run.

Scheduled commands start runs, collect batches, re-test paused engines, sync keywords, send reports and close stuck runs.

Out of the box, every job goes to your app's **default queue connection** and the queue named **`default`**. One `php artisan queue:work` process is enough to get started.

## Queue settings

`config/ai-visibility.php`:

```php
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
```

| Env var | Sets | Default |
|---|---|---|
| `AI_VISIBILITY_QUEUE_CONNECTION` | The queue connection for every AI Visibility job | Your app's `QUEUE_CONNECTION` |
| `AI_VISIBILITY_QUEUE` | One queue name for all three keys below | `default` |
| `AI_VISIBILITY_QUEUE_TRACKING` | Queue for answer jobs | `AI_VISIBILITY_QUEUE`, then `default` |
| `AI_VISIBILITY_QUEUE_ANALYSIS` | Queue for alert checks | `AI_VISIBILITY_QUEUE`, then `default` |
| `AI_VISIBILITY_QUEUE_CLASSIFICATION` | Queue for discovery, classification and re-detection | `AI_VISIBILITY_QUEUE`, then `default` |

The connection must not use the `sync` driver. Manual runs are refused on `sync`, and the Health page reports it as a failure.

## Jobs

| Job | What it does | Dispatched when | Queue key | Own timeout | Retries | Typical duration |
|---|---|---|---|---|---|---|
| `RunResultJob` | Asks one prompt on one engine with web search, stores the answer, mentions, sources and cost. | A run starts (one job per answer), **Retry skipped** on a run, or economy-mode answers that must be retried in real time. | `tracking` | 240 s | Retries rate limits and outages until its slot + `tracking.retry_for_seconds` (at least 1 hour). Up to 3 unexpected errors. | 10–60 s. Up to about 3 minutes (Claude can continue a long search for up to three turns). |
| `SubmitBatchJob` | Economy mode: sends one engine's share of a scheduled run to the provider's batch API. | A scheduled run starts with economy mode on (one job per engine per `economy.max_batch_size` answers). | `tracking` | Worker's | Never retried. If it fails, the answers are asked in real time instead. | A few seconds (one upload). |
| `EvaluateAlertsJob` | Checks alert rules for the brand against the new answers. | A run finishes. | `analysis` | Worker's | 2 tries | Seconds. |
| `DiscoverCompetitorsJob` | Answer analysis (sentiment, recommendation, descriptors), name extraction, competitor discovery, scoring and classification, then "new direct competitor" alerts. | A run finishes with at least one answer (when analysis or discovery is on); daily `ai-visibility:discover --queue`; **Discover now** on the Discovered screen. | `classification` | 900 s | Waits up to 3 hours for another discovery of the same brand to finish; 2 real failures allowed. One per brand at a time. | Seconds to several minutes (helper AI calls in batches, website fetches). |
| `ClassifyCandidatesJob` | Classifies chosen candidates again. | **Classify again** on the Discovered screen. | `classification` | 900 s | Waits up to 3 hours for a running discovery; 1 real failure allowed. | Seconds to minutes. |
| `RedetectBrandJob` | Re-checks a brand's stored answers for brand and competitor mentions, 250 answers per job; each job queues the next chunk. | A brand's name, aliases, domains or exclusions change; a competitor is added, removed or renamed. | `classification` | 900 s | 2 tries | Seconds per chunk. |
| `QueueHeartbeat` | Records that a worker processed a job, for the Health page and the stall alert. | Every 5 minutes from the scheduler, and when the Health page or setup wizard opens or **Re-check** is clicked. Sent to every distinct configured queue. | every queue | Worker's | 1 try | Instant. |

Notes:

- Answer analysis runs inside `DiscoverCompetitorsJob`, so it uses the `classification` queue, not `analysis`.
- Every job carries its tenant ID and runs in that tenant's context.
- Long-running workers see settings saved in the panel straight away. Settings are re-read before each job.

## Scheduled tasks

The package registers these with Laravel's scheduler. You do not add them yourself.

| Task | Frequency | What it does |
|---|---|---|
| `ai-visibility:scheduler-heartbeat` | Every minute | Records that the scheduler runs (Health page). |
| `ai-visibility:queue-heartbeat:{queue}` | Every 5 minutes | One per distinct configured queue: queues a `QueueHeartbeat` on that queue. |
| `ai-visibility:run --due` | Every 15 minutes, without overlapping | Closes stuck runs, then starts every brand whose scheduled run is due. |
| `ai-visibility:probe` | Every 5 minutes, without overlapping | Re-tests paused engines and resumes those that work; lifts budget pauses when budget is available again. |
| `ai-visibility:poll-batches` | Every 5 minutes, without overlapping | Stores answers from finished economy-mode batches. |
| `ai-visibility:alerts --watch` | Every 10 minutes | Queue watchdog: alerts if no worker has processed a job for `health.queue_critical_after` minutes. |
| `ai-visibility:alerts` | Daily at 07:00 | Checks every alert rule for every active brand (plus the queue watchdog). |
| `ai-visibility:discover --queue` | Daily at 04:30 | Queues discovery for every active brand (stale classifications are refreshed). |
| `ai-visibility:sync-keywords` | Daily at 05:00 | Syncs keyword sources that are due (`keywords.sync_days`, 30 by default). |
| `ai-visibility:send-reports` | Hourly, without overlapping | Sends scheduled email reports that are due. |
| `ai-visibility:sweep-runs` | Hourly, without overlapping | Closes runs with no progress for `tracking.stale_run_hours`. |
| `ai-visibility:prune` | Daily 03:30, without overlapping | Removes the text of answers older than Settings → "Keep full answer text for"; metrics are kept. |

Times use your app's timezone. Brands run at the time set in **Settings → Runs & limits** (default 03:00), checked every 15 minutes.

"Without overlapping" uses a cache lock. If a server dies while one of these commands runs, the lock can block that command for up to 24 hours. Clear it with `php artisan schedule:clear-cache`.

None of the tasks use `onOneServer()`. **Run `schedule:run` on one server only.** See [multiple servers](#cache-rate-limits-and-multiple-servers).

## retry_after and worker timeout (required)

This is the most common production mistake.

Answer jobs can take up to **240 seconds**. Discovery, classification and re-check jobs can take up to **900 seconds**. Laravel's default `retry_after` is **90 seconds**. When a job runs longer than `retry_after`, the queue hands it to a second worker while the first is still working. You then see jobs "running twice", `MaxAttemptsExceededException` errors, and wasted worker time.

Set the queue connection AI Visibility uses to:

- `retry_after` **at least 960** seconds (longest job 900 s + margin).
- Worker `--timeout=930` (shorter than `retry_after`, longer than the longest job).

For Laravel's stock `config/queue.php` you can do it in `.env`:

```dotenv
# database queue
DB_QUEUE_RETRY_AFTER=960
# or redis queue
REDIS_QUEUE_RETRY_AFTER=960
```

```bash
php artisan queue:work --timeout=930
```

`retry_after` is set per connection. Raising it on your app's main connection also means a job from a crashed worker is retried after 16 minutes instead of 90 seconds, for your app's jobs too. If that matters, give AI Visibility its own connection ([setup c](#c-a-dedicated-redis-connection)).

Jobs with their own timeout (240 s or 900 s) use it instead of the worker's `--timeout`. The worker's timeout applies to the others. Job timeouts need the `pcntl` PHP extension, which is not available on Windows.

## Throughput: how many workers you need

Each answer job waits on a slow web-search API call: usually **10 to 60 seconds**. A worker is blocked while it waits. So **one worker handles only about 1 to 6 answers per minute**, and a run with one worker is slow.

Example: 50 active prompts × 5 engines × 1 sample = 250 answers. At 30 seconds each, one worker needs about 2 hours.

Workers mostly wait on the network and use little CPU, so you can run many of them. Estimate:

```
workers ≈ requests_per_minute × average seconds per answer ÷ 60
```

Example: 20 requests per minute × 30 seconds ÷ 60 = **10 workers** to keep one engine busy at its limit.

The rate limit caps what more workers can do:

- **Settings → Engines & API keys → Requests per minute, per engine** (default 20) limits each engine, per tenant. Answer jobs are spread out on a schedule at that rate when a run starts.
- An engine cannot go faster than its limit, however many workers you add. Extra workers find nothing ready to run.
- Different engines have separate limits and run in parallel. If several engines run at once, add up the estimate for each engine.
- An engine that gets rate-limited by the provider is slowed to half its requests per minute for a while.

Minimum run time per engine is `answers for that engine ÷ requests per minute` minutes, even with enough workers. Raise the requests per minute only if your provider account allows it.

## Ready-made setups

### a) Small app: one worker

Everything on the default queue. Good for trying it out and for a few brands with few prompts.

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=960
```

```bash
php artisan queue:work --timeout=930
```

Runs will be slow (see [throughput](#throughput-how-many-workers-you-need)), and AI Visibility jobs share the worker with your app's jobs.

### b) Recommended: separate queues

Answer jobs on their own queue with several workers. Short and long background jobs on another queue with one worker. Your app's `default` queue is left alone.

```dotenv
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=960

AI_VISIBILITY_QUEUE_TRACKING=aiv-tracking
AI_VISIBILITY_QUEUE_ANALYSIS=aiv-background
AI_VISIBILITY_QUEUE_CLASSIFICATION=aiv-background
```

Workers (run the first one several times, see [Supervisor](#supervisor)):

```bash
# tracking: several processes
php artisan queue:work redis --queue=aiv-tracking --timeout=930

# background: one process
php artisan queue:work redis --queue=aiv-background --timeout=930

# your app's own worker, unchanged
php artisan queue:work redis --queue=default
```

The same works with the `database` driver: use `database` instead of `redis` and `DB_QUEUE_RETRY_AFTER=960`.

### c) A dedicated Redis connection

Keeps AI Visibility's long `retry_after` away from your app's jobs. Add a connection to `config/queue.php`:

```php
'connections' => [
    // ...

    'ai-visibility' => [
        'driver' => 'redis',
        'connection' => env('AI_VISIBILITY_REDIS_CONNECTION', 'default'),
        'queue' => 'aiv-tracking',
        'retry_after' => 960,
        'block_for' => null,
        'after_commit' => false,
    ],
],
```

```dotenv
AI_VISIBILITY_QUEUE_CONNECTION=ai-visibility
AI_VISIBILITY_QUEUE_TRACKING=aiv-tracking
AI_VISIBILITY_QUEUE_ANALYSIS=aiv-background
AI_VISIBILITY_QUEUE_CLASSIFICATION=aiv-background
```

```bash
php artisan queue:work ai-visibility --queue=aiv-tracking --timeout=930
php artisan queue:work ai-visibility --queue=aiv-background --timeout=930
```

Your app's own connection can keep `retry_after` at 90.

## Supervisor

For setup b or c. Adjust paths, user, connection name and `numprocs`. `stopwaitsecs` gives a running job time to finish when Supervisor stops the worker (for example during a deploy).

```ini
; /etc/supervisor/conf.d/ai-visibility.conf

[program:aiv-tracking]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app/artisan queue:work redis --queue=aiv-tracking --timeout=930 --sleep=3 --max-time=3600
directory=/var/www/app
user=www-data
numprocs=10
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=960
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/aiv-tracking.log

[program:aiv-background]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app/artisan queue:work redis --queue=aiv-background --timeout=930 --sleep=3 --max-time=3600
directory=/var/www/app
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=960
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/aiv-background.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start "aiv-tracking:*" "aiv-background:*"
```

For setup c, replace `redis` with `ai-visibility` in both commands. After each deploy run `php artisan queue:restart`.

## Laravel Horizon

Horizon only works with Redis. Add supervisors to `config/horizon.php`:

```php
'environments' => [
    'production' => [
        // your app's existing supervisor(s)...

        'aiv-tracking' => [
            'connection' => 'redis',          // or 'ai-visibility' for setup c
            'queue' => ['aiv-tracking'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'timeout' => 930,
            'tries' => 1,
            'memory' => 256,
        ],

        'aiv-background' => [
            'connection' => 'redis',          // or 'ai-visibility'
            'queue' => ['aiv-background'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'timeout' => 930,
            'tries' => 1,
            'memory' => 256,
        ],
    ],
],
```

Notes:

- `timeout` must stay below the connection's `retry_after` (960).
- `tries => 1` is fine. Every AI Visibility job sets its own tries or a retry time limit, which win over the supervisor's value.
- Answer jobs release themselves back to the queue while they wait for their rate-limit slot. Horizon counts these as retries in its metrics; that is expected.
- On deploy, run `php artisan horizon:terminate`. It lets running jobs finish before Horizon stops. If Horizon itself runs under Supervisor, give that program `stopwaitsecs=960`.

## Local development (Windows / Laragon)

There is no Supervisor or cron on Windows. Use two terminals in the project folder:

```bash
# terminal 1: the scheduler (runs schedule:run every minute)
php artisan schedule:work

# terminal 2: a worker
php artisan queue:work --timeout=930
```

Tips:

- `QUEUE_CONNECTION=database` and `CACHE_STORE=database` (or `file`) work well locally.
- Set `DB_QUEUE_RETRY_AFTER=960` locally too, or long jobs run twice.
- Windows PHP has no `pcntl`, so job timeouts are not enforced. A stuck HTTP call still ends after `http.timeout` (60 s).
- Open more terminals with `php artisan queue:work` to speed up runs.
- Use `php artisan queue:listen` instead of `queue:work` if you edit code and want each job to load it fresh (slower).
- After a code change, restart `queue:work` (Ctrl+C and start again).

## Cache, rate limits and multiple servers

AI Visibility uses your app's **default cache store** for:

- the per-engine rate limiter and the pacing schedule of answer jobs,
- locks (engine state creation, one discovery per brand, unique jobs, scheduler "without overlapping"),
- alert throttling.

With more than one server or worker machine, all of them must share one cache: use `redis`, `database` or `memcached`. Do not use `file` or `array` across servers. Otherwise each machine keeps its own rate limit (so engines get more requests than the limit) and locks do not protect anything.

Also, on every server:

- Use the same `APP_KEY`. Saved API keys are encrypted with it.
- Use the same config and env values (queues, keys).
- Run the scheduler on **one** server only. The package's tasks do not use `onOneServer()`. Some commands are safe to run twice (reports are claimed before sending, due runs check the last run time), but others would do duplicate work.

## What the Health page shows

**AI Visibility → Health** (and `php artisan ai-visibility:health`) checks:

| Check | OK | Warning / failure |
|---|---|---|
| Database tables | Tables exist | Failed: run the migrations. |
| Queue connection | Not `sync` | Failed on `sync`: set `QUEUE_CONNECTION=database` (or redis) and start a worker. |
| Queue worker (one check per distinct queue; with several queues the label names the queue and what it is for, e.g. `Queue worker: "aiv-background" (analysis, classification)`) | A heartbeat was processed recently. Also shows how many jobs are waiting, where the driver can tell (database, redis, SQS). | Warning after `health.queue_warning_after` minutes (15) without a heartbeat on that queue; failed after `health.queue_critical_after` (60), or if no worker ever processed one. The fix names the queue: `php artisan queue:work --queue=<name> --timeout=930`. |
| Scheduler | Heartbeat within the last minutes | Warning after 5 minutes, failed after 60. The fix shows the cron line. |

When a worker is missing for one queue, only that queue's check fails. For example, with setup b and no `aiv-background` worker, tracking still works but analysis, discovery and alerts wait.

Opening the Health page or clicking **Re-check** sends a fresh heartbeat job to each queue, so a check turns green within seconds once a worker runs.

A large run can queue thousands of answer jobs. Many of them are delayed on purpose (pacing), so a high "waiting" count during a run is normal. A long backlog can also delay the heartbeat behind other jobs; if the worker check warns during a big run, look at the waiting count before assuming the worker stopped.

If a queue's heartbeat has not been processed for `health.queue_critical_after` minutes, an always-on alert is sent for that queue, naming what is waiting and the worker command (at most once every 6 hours per queue and tenant). A new install gets 30 minutes before this alert. The setup wizard's System step shows the same per-queue checks.
