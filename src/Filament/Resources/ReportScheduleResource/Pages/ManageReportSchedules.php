<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;

class ManageReportSchedules extends ManageRecords
{
    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New scheduled report')
                ->mutateDataUsing(function (array $data) {
                    $schedule = new ReportSchedule($data);
                    $data['next_send_at'] = $schedule->nextSendAfter(now());

                    return $data;
                }),
        ];
    }
}
