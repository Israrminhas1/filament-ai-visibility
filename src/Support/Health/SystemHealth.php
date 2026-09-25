<?php

namespace IsrarMinhas\FilamentAiVisibility\Support\Health;

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

    public static function queueHeartbeatName(): string
    {
        return 'queue:' . static::queueName();
    }

    public static function queueName(): string
    {
        return config('ai-visibility.queues.tracking', 'default');
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
            $this->queueWorker(),
            $this->scheduler(),
        ];
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

    public function queueWorker(): CheckResult
    {
        $lastBeat = Heartbeat::lastBeat(static::queueHeartbeatName());
        $warning = (int) config('ai-visibility.health.queue_warning_after', 15);
        $critical = (int) config('ai-visibility.health.queue_critical_after', 60);
        $fix = 'Start a worker for the "' . static::queueName() . '" queue and keep it running (e.g. with Supervisor): php artisan queue:work --queue=' . static::queueName();

        if (! $lastBeat) {
            return new CheckResult('queue_worker', 'Queue worker', CheckResult::FAILED, 'No queue worker has processed an AI Visibility job yet.', $fix, blocking: true);
        }

        $minutes = (int) $lastBeat->diffInMinutes(now(), absolute: true);

        return match (true) {
            $minutes >= $critical => new CheckResult('queue_worker', 'Queue worker', CheckResult::FAILED, "The last queued job was processed {$lastBeat->diffForHumans()}.", $fix, blocking: true),
            $minutes >= $warning => new CheckResult('queue_worker', 'Queue worker', CheckResult::WARNING, "The last queued job was processed {$lastBeat->diffForHumans()}.", $fix),
            default => new CheckResult('queue_worker', 'Queue worker', CheckResult::OK, "Worker is processing jobs (last heartbeat {$lastBeat->diffForHumans()})."),
        };
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
        try {
            QueueHeartbeat::dispatch();
        } catch (Throwable) {
            //
        }
    }
}
