<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use IsrarMinhas\FilamentAiVisibility\Alerts\AlertType;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\AlertRuleResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\AlertRule;

class AlertRuleResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = AlertRule::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 81;

    protected static ?string $modelLabel = 'alert rule';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/alert-rules';
    }

    public static function getNavigationLabel(): string
    {
        return 'Alert rules';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-bell';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('type')
                    ->label('Alert me when')
                    ->options(AlertType::class)
                    ->required()
                    ->live(),
                Text::make(fn (Get $get) => ($type = static::type($get)) ? $type->getDescription() : '')
                    ->visible(fn (Get $get) => static::type($get) !== null),
                Select::make('brand_id')
                    ->label('Brand')
                    ->options(PromptTable::brandOptions())
                    ->placeholder('All brands')
                    ->live()
                    ->visible(fn (Get $get) => static::type($get)?->isBrandLevel() ?? true),
                ...collect(AlertType::cases())
                    ->map(fn (AlertType $type) => Group::make($type->fields())->visible(fn (Get $get) => static::type($get) === $type))
                    ->all(),
                CheckboxList::make('channels')
                    ->label('Send to')
                    ->options(['database' => 'Panel notifications', 'mail' => 'Email', 'slack' => 'Slack'])
                    ->default(['database', 'mail', 'slack'])
                    ->helperText('Recipients are set under Settings → Alerts. Every alert also appears in the Alerts inbox.')
                    ->columns(3),
                TextInput::make('cooldown_hours')
                    ->label('Do not repeat within (hours)')
                    ->numeric()
                    ->minValue(1)
                    ->default(24),
                TextInput::make('name')->label('Name (optional)')->maxLength(255),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    protected static function type(Get $get): ?AlertType
    {
        $type = $get('type');

        return $type instanceof AlertType ? $type : AlertType::tryFrom((string) $type);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('brand'))
            ->columns([
                TextColumn::make('type')
                    ->label('Alert')
                    ->formatStateUsing(fn (AlertRule $record) => $record->label())
                    ->description(fn (AlertRule $record) => $record->type->getDescription())
                    ->wrap(),
                TextColumn::make('brand.name')->label('Brand')->placeholder('All brands'),
                TextColumn::make('channels')->badge()->formatStateUsing(fn (string $state) => ['database' => 'Panel', 'mail' => 'Email', 'slack' => 'Slack'][$state] ?? $state),
                TextColumn::make('last_triggered_at')->label('Last sent')->since()->placeholder('Never'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No alert rules yet')
            ->emptyStateDescription('Engine pauses, stopped workers and budget stops are always alerted. Add rules for visibility drops, competitors and more.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAlertRules::route('/')];
    }
}
