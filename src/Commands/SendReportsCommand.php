<?php

namespace IsrarMinhas\FilamentAiVisibility\Commands;

use Illuminate\Console\Command;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportSender;
use IsrarMinhas\FilamentAiVisibility\Support\Tenancy;

class SendReportsCommand extends Command
{
    protected $signature = 'ai-visibility:send-reports';

    protected $description = 'Send scheduled AI Visibility email reports that are due';

    public function handle(ReportSender $sender): int
    {
        $due = ReportSchedule::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->where('next_send_at', '<=', now())
            // Reports for paused brands wait until the brand is active again.
            ->whereIn('brand_id', Brand::query()->withoutGlobalScopes()->where('is_active', true)->select('id'))
            ->get();

        foreach ($due as $schedule) {
            $sent = Tenancy::as($schedule->tenant_id, fn () => $sender->sendDue($schedule));

            match ($sent) {
                true => $this->components->info("Sent \"{$schedule->name}\"."),
                false => $this->components->error("Could not send \"{$schedule->name}\"."),
                null => $this->components->warn("Skipped \"{$schedule->name}\": already being sent elsewhere."),
            };
        }

        return self::SUCCESS;
    }
}
