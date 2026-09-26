<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Illuminate\Support\Facades\Mail;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\Alert;
use IsrarMinhas\FilamentAiVisibility\Support\Alerts\AlertNotifier;
use Throwable;

class ReportSender
{
    public function __construct(
        protected ReportBuilder $builder,
        protected AlertNotifier $alerts,
    ) {}

    public function send(ReportSchedule $schedule): bool
    {
        try {
            Mail::to($schedule->recipients)->send(new ReportMail($this->builder->forSchedule($schedule), $schedule->attach_pdf));
        } catch (Throwable $e) {
            $schedule->forceFill(['last_error' => $e->getMessage(), 'next_send_at' => now()->addHours(6)])->save();

            $this->alerts->send(new Alert(
                title: "Report \"{$schedule->name}\" could not be sent",
                body: $e->getMessage(),
                level: 'danger',
                type: 'report_failed',
                brandId: $schedule->brand_id,
            ), ['database']);

            return false;
        }

        $schedule->forceFill([
            'last_sent_at' => now(),
            'last_error' => null,
            'next_send_at' => $schedule->nextSendAfter(now()),
        ])->save();

        return true;
    }
}
