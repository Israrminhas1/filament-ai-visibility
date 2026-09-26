<?php

namespace IsrarMinhas\FilamentAiVisibility\Support\Health;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use IsrarMinhas\FilamentAiVisibility\Jobs\QueueHeartbeat;
use IsrarMinhas\FilamentAiVisibility\Models\Heartbeat;
use IsrarMinhas\FilamentAiVisibility\Models\Model;
use Throwable;

/**
 * System checks shown in the setup wizard and on the Health page.
 */
class SystemHealth
{
    public const SCHEDULER = 'scheduler';

    /**
     * What each queue key is for, shown when the plugin uses more than one queue.
     */
    protected const QUEUE_PURPOSES = [
        'tracking' => 'answering prompts',
        'analysis' => 'alerts',
        'classification' => 'competitor discovery and re-checks',
    ];

    public static function queueHeartbeatName(?string $queue = null): string
    {
        return 'queue:' . ($queue ?? static::queueName());
    }

    /**
     * The tracking queue, where most of the work happens.
     */
    public static function queueName(): string
    {
        return config('ai-visibility.queues.tracking') ?: 'default';
    }

    /**
     * Every distinct queue the plugin sends jobs to, each needing a worker.
     *
     * @return array<string, array<string>> Queue name → what it is used for.
     */
    public static function queues(): array
    {
        $queues = [];

        foreach (self::QUEUE_PURPOSES as $key => $purpose) {
            $queues[config("ai-visibility.queues.{$key}") ?: 'default'][] = $purpose;
        }

        return $queues;
    }

    public static function queueConnection(): string
    {
        return config('ai-visibility.queues.connection') ?: config('queue.default');
    }

    /**
     * @return array<CheckResult>
     */
    public function checks(): array
    {
        return [
            $this->migrations(),
            $this->queueDriver(),
            ...$this->queueWorkers(),
            $this->scheduler(),
        ];
    }

    /**
     * One worker check per queue the plugin uses.
     *
     * @return array<CheckResult>
     */
    public function queueWorkers(): array
    {
        return array_map(fn (string $queue) => $this->queueWorker($queue), array_keys(static::queues()));
    }

    public function hasBlockingFailures(): bool
    {
        return collect($this->checks())->contains(fn (CheckResult $check) => $check->blocking && ! $check->ok());
    }

    public function migrations(): CheckResult
    {
        $ok = Schema::hasTable(Model::prefixedTable('brands')) && Schema::hasTable(Model::prefixedTable('heartbeats'));

        return new CheckResult(
            'migrations',
            'Database tables',
            $ok ? CheckResult::OK : CheckResult::FAILED,
            $ok ? 'All AI Visibility tables exist.' : 'The AI Visibility tables are missing.',
            $ok ? null : 'Run: php artisan vendor:publish --tag="ai-visibility-migrations" && php artisan migrate',
            blocking: true,
        );
    }

    public function queueDriver(): CheckResult
    {
        $connection = static::queueConnection();
        $driver = config("queue.connections.{$connection}.driver");
        $sync = $driver === 'sync' || $driver === null;

        return new CheckResult(
            'queue_driver',
            'Queue connection',
            $sync ? CheckResult::FAILED : CheckResult::OK,
            $sync
                ? "The queue connection \"{$connection}\" runs jobs synchronously, so runs would block web requests and time out."
                : "Using the \"{$connection}\" connection ({$driver}).",
            $sync ? 'Set QUEUE_CONNECTION=database (or redis) in .env, then start a worker: php artisan queue:work' : null,
            blocking: true,
        );
    }

    public function queueWorker(?string $queue = null): CheckResult
    {
        $queue ??= static::queueName();
        $queues = static::queues();
        $several = count($queues) > 1;

        // With one queue the check keeps its simple name; with several, each says what it is for.
        $key = $several ? "queue_worker:{$queue}" : 'queue_worker';
        $label = $several ? "Queue worker: \"{$queue}\" (" . implode(', ', $queues[$queue] ?? []) . ')' : 'Queue worker';

        $lastBeat = Heartbeat::lastBeat(static::queueHeartbeatName($queue));
        $warning = (int) config('ai-visibility.health.queue_warning_after', 15);
        $critical = (int) config('ai-visibility.health.queue_critical_after', 60);
        $fix = "Start a worker for the \"{$queue}\" queue and keep it running (e.g. with Supervisor): php artisan queue:work --queue={$queue} --timeout=930";
        $waiting = $this->waiting($queue);
        $backlog = $waiting > 0 ? " {$waiting} jobs waiting." : '';

        if (! $lastBeat) {
            return new CheckResult($key, $label, CheckResult::FAILED, 'No queue worker has processed an AI Visibility job on this queue yet.' . $backlog, $fix, blocking: true);
        }

        $minutes = (int) $lastBeat->diffInMinutes(now(), absolute: true);

        return match (true) {
            $minutes >= $critical => new CheckResult($key, $label, CheckResult::FAILED, "The last queued job was processed {$lastBeat->diffForHumans()}.{$backlog}", $fix, blocking: true),
            $minutes >= $warning => new CheckResult($key, $label, CheckResult::WARNING, "The last queued job was processed {$lastBeat->diffForHumans()}.{$backlog}", $fix),
            default => new CheckResult($key, $label, CheckResult::OK, "Worker is processing jobs (last heartbeat {$lastBeat->diffForHumans()}).{$backlog}"),
        };
    }

    /**
     * Jobs waiting on a queue, where the driver can tell (database, redis, SQS).
     */
    public function waiting(string $queue): int
    {
        return (int) rescue(fn () => Queue::connection(static::queueConnection())->size($queue), 0, report: false);
    }

    public function scheduler(): CheckResult
    {
        $lastBeat = Heartbeat::lastBeat(static::SCHEDULER);
        $warning = (int) config('ai-visibility.health.scheduler_warning_after', 5);
        $critical = (int) config('ai-visibility.health.scheduler_critical_after', 60);
        $fix = 'Add this cron entry on your server: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1';

        if (! $lastBeat) {
            return new CheckResult('scheduler', 'Scheduler', CheckResult::FAILED, 'The Laravel scheduler has not run yet, so scheduled tracking would never start.', $fix, blocking: true);
        }

        $minutes = (int) $lastBeat->diffInMinutes(now(), absolute: true);

        return match (true) {
            $minutes >= $critical => new CheckResult('scheduler', 'Scheduler', CheckResult::FAILED, "The scheduler last ran {$lastBeat->diffForHumans()}.", $fix, blocking: true),
            $minutes >= $warning => new CheckResult('scheduler', 'Scheduler', CheckResult::WARNING, "The scheduler last ran {$lastBeat->diffForHumans()}.", $fix),
            default => new CheckResult('scheduler', 'Scheduler', CheckResult::OK, "Scheduler is running (last heartbeat {$lastBeat->diffForHumans()})."),
        };
    }

    /**
     * Queue a heartbeat job; the check passes once a worker runs it.
     */
    public function pingQueue(): void
    {
        foreach (array_keys(static::queues()) as $queue) {
            try {
                QueueHeartbeat::dispatch($queue);
            } catch (Throwable) {
                //
            }
        }
    }
}
