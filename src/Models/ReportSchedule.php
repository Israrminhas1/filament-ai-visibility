<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected string $baseTable = 'report_schedules';

    protected $casts = [
        'recipients' => 'array',
        'sections' => 'array',
        'attach_pdf' => 'bool',
        'is_active' => 'bool',
        'next_send_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Days covered by each report.
     */
    public function periodDays(): int
    {
        return $this->frequency === 'monthly' ? 30 : 7;
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
}
