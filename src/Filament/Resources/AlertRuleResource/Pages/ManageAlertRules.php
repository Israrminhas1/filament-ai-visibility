<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource;

class ManageAlertRules extends ManageRecords
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New alert rule')];
    }
}
