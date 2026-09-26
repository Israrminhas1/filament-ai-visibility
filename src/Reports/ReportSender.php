<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Illuminate\Support\Facades\Mail;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use Throwable;

class ReportSender
{
    /**
     * How long a claimed report is held before another server may retry it.
     */
    public const CLAIM_MINUTES = 60;

    public function __construct(
        protected ReportBuilder $builder,
        protected AlertNotifier $alerts,
    ) {}

    /**
     * Send a report that is due, unless another server already claimed it.
     *
     * @return bool|null Null when it was not ours to send.
     */
    public function sendDue(ReportSchedule $schedule): ?bool
    {
        return $this->claim($schedule) ? $this->send($schedule) : null;
    }

    /**
     * Move the due time forward only if nobody else has, so with several
     * servers running the scheduler each report goes out once.
     */
    public function claim(ReportSchedule $schedule): bool
    {
        $due = $schedule->getRawOriginal('next_send_at');

        if ($due === null) {
            return false;
        }

        $until = now()->addMinutes(self::CLAIM_MINUTES);

        $claimed = ReportSchedule::query()->withoutGlobalScopes()
            ->whereKey($schedule->getKey())
            ->where('is_active', true)
            ->where('next_send_at', $due)
            ->update(['next_send_at' => $until]);

        if ($claimed !== 1) {
            return false;
        }

        $schedule->forceFill(['next_send_at' => $until])->syncOriginalAttribute('next_send_at');

        return true;
    }

    public function send(ReportSchedule $schedule): bool
    {
        try {
            Mail::to($schedule->recipients)->send(new ReportMail($this->builder->forSchedule($schedule), $schedule->attach_pdf));
        } catch (Throwable $e) {
            $this->failed($schedule, $e);

            return false;
        }

        $schedule->resetFailures();
        $schedule->forceFill([
            'last_sent_at' => now(),
            'last_error' => null,
            'next_send_at' => $schedule->nextSendAfter(now()),
        ])->save();

        return true;
    }

    /**
     * Retry in 6 hours; after a few failures in a row, pause the schedule and
     * say so once instead of retrying forever.
     */
    protected function failed(ReportSchedule $schedule, Throwable $e): void
    {
        $failures = $schedule->recordFailure();
        $paused = $failures >= ReportSchedule::MAX_FAILURES;

        $schedule->forceFill([
            'last_error' => $e->getMessage(),
            'next_send_at' => now()->addHours(6),
        ] + ($paused ? ['is_active' => false] : []))->save();

        if (! $paused) {
            return;
        }

        $this->alerts->send(new Alert(
            title: "Report \"{$schedule->name}\" was paused",
            body: "It could not be sent {$failures} times in a row. Last error: {$e->getMessage()} Fix the problem, then turn the schedule back on.",
            level: 'danger',
            type: 'report_failed',
            brandId: $schedule->brand_id,
        ), ['database']);
    }
}
