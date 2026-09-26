<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use IsrarMinhas\FilamentAiVisibility\Models\Concerns\BelongsToTenant;

class ReportSchedule extends Model
{
    use BelongsToTenant;

    public const SECTIONS = [
        'summary' => 'Summary',
        'competitors' => 'Competitor leaderboard',
        'opportunities' => 'Opportunities',
        'sources' => 'Top sources',
        'prompts' => 'Prompt movers',
        'perception' => 'How AI talks about you',
    ];

    /**
     * Failed sends in a row before the schedule is paused.
     */
    public const MAX_FAILURES = 3;

    protected string $baseTable = 'report_schedules';

    protected $casts = [
        'recipients' => 'array',
        'sections' => 'array',
        'attach_pdf' => 'bool',
        'is_active' => 'bool',
        'next_send_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    protected static ?bool $hasFailuresColumn = null;

    protected static function booted(): void
    {
        static::saving(function (ReportSchedule $schedule) {
            // A changed frequency means a new send day.
            if (($schedule->exists && $schedule->isDirty('frequency')) || ! $schedule->next_send_at) {
                $schedule->next_send_at = $schedule->nextSendAfter(now());
            }

            // Turned back on (e.g. after being paused for failures): start counting again.
            if ($schedule->exists && $schedule->isDirty('is_active') && $schedule->is_active) {
                $schedule->resetFailures();
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Days covered by each report.
     */
    public function periodDays(?CarbonInterface $at = null): int
    {
        [$from, $until] = $this->period($at);

        return (int) round($from->diffInDays($until));
    }

    /**
     * The whole days a report sent at $at covers: the previous calendar month
     * for monthly reports, the 7 days before the send day for weekly ones.
     * The send day itself is left out, so no report ends on a partial day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(?CarbonInterface $at = null): array
    {
        $day = CarbonImmutable::instance($at ?? now())->startOfDay();

        return $this->frequency === 'monthly'
            ? [$day->startOfMonth()->subMonthNoOverflow(), $day->startOfMonth()->subSecond()]
            : [$day->subDays(7), $day->subSecond()];
    }

    /**
     * Mondays at 08:00 for weekly, the 1st at 08:00 for monthly.
     */
    public function nextSendAfter(CarbonInterface $from): CarbonInterface
    {
        return $this->frequency === 'monthly'
            ? $from->copy()->addMonthNoOverflow()->startOfMonth()->setTime(8, 0)
            : $from->copy()->next(CarbonInterface::MONDAY)->setTime(8, 0);
    }

    /**
     * @return array<string>
     */
    public function enabledSections(): array
    {
        return $this->sections ?: array_keys(static::SECTIONS);
    }

    /**
     * Failed sends since the last successful one.
     */
    public function failures(): int
    {
        // Read the raw value: getAttribute() on an unloaded column would call this
        // method again (Eloquent treats a same-named method as a relation).
        return static::hasFailuresColumn()
            ? (int) ($this->getAttributes()['failures'] ?? 0)
            : (int) Cache::get($this->failuresCacheKey(), 0);
    }

    /**
     * Count one more failed send and return the new total. Saved with the
     * model when the table has a failures column.
     */
    public function recordFailure(): int
    {
        $count = $this->failures() + 1;

        static::hasFailuresColumn()
            ? $this->setAttribute('failures', $count)
            : Cache::put($this->failuresCacheKey(), $count, now()->addDays(90));

        return $count;
    }

    public function resetFailures(): void
    {
        static::hasFailuresColumn()
            ? $this->setAttribute('failures', 0)
            : Cache::forget($this->failuresCacheKey());
    }

    /**
     * Older installs have no failures column; the count is then kept in the cache.
     */
    protected static function hasFailuresColumn(): bool
    {
        return static::$hasFailuresColumn ??= (bool) rescue(
            fn () => Schema::connection((new static)->getConnectionName())->hasColumn((new static)->getTable(), 'failures'),
            false,
            report: false,
        );
    }

    protected function failuresCacheKey(): string
    {
        return 'ai-visibility:report-failures:' . $this->getKey();
    }
}
