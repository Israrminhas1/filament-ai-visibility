<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use IsrarMinhas\FilamentAiVisibility\Filament\Concerns\HasAiVisibilityNavigation;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ReportScheduleResource\Pages;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\ReportSchedule;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportBuilder;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportMail;
use IsrarMinhas\FilamentAiVisibility\Reports\ReportSender;

class ReportScheduleResource extends Resource
{
    use HasAiVisibilityNavigation;

    protected static ?string $model = ReportSchedule::class;

    protected static bool $isScopedToTenant = false;

    protected static int $aiVisibilitySort = 82;

    protected static ?string $modelLabel = 'scheduled report';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'ai-visibility/scheduled-reports';
    }

    public static function getNavigationLabel(): string
    {
        return 'Scheduled reports';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-envelope';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('brand_id')->label('Brand')->options(PromptTable::brandOptions())->required(),
                TextInput::make('name')->required()->maxLength(255)->default('Weekly AI visibility'),
                Select::make('frequency')
                    ->options(['weekly' => 'Weekly (Mondays, last 7 days)', 'monthly' => 'Monthly (1st, last 30 days)'])
                    ->default('weekly')
                    ->required(),
                Toggle::make('attach_pdf')
                    ->label('Attach a PDF')
                    ->inline(false)
                    ->disabled(fn () => ! ReportMail::canMakePdf())
                    ->helperText(fn () => ReportMail::canMakePdf() ? null : 'Install dompdf/dompdf to attach PDFs.'),
                TagsInput::make('recipients')
                    ->placeholder('name@example.com')
                    ->nestedRecursiveRules(['email'])
                    ->required()
                    ->columnSpanFull(),
                CheckboxList::make('sections')
                    ->options(ReportSchedule::SECTIONS)
                    ->default(array_keys(ReportSchedule::SECTIONS))
                    ->columns(3)
                    ->columnSpanFull(),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('brand'))
            ->columns([
                TextColumn::make('name')->description(fn (ReportSchedule $record) => implode(', ', $record->recipients ?? [])),
                TextColumn::make('brand.name')->label('Brand'),
                TextColumn::make('frequency')->badge(),
                TextColumn::make('next_send_at')->label('Next')->dateTime('M j, H:i')->placeholder('—'),
                TextColumn::make('last_sent_at')->label('Last sent')->since()->placeholder('Never')
                    ->description(fn (ReportSchedule $record) => $record->last_error ? 'Failed: ' . str($record->last_error)->limit(60) : null),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->recordActions([
                Action::make('sendNow')
                    ->label('Send now')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->action(function (ReportSchedule $record) {
                        $sent = app(ReportSender::class)->send($record);

                        Notification::make()
                            ->title($sent ? 'Report sent' : 'Could not send the report')
                            ->body($sent ? null : $record->fresh()->last_error)
                            ->status($sent ? 'success' : 'danger')
                            ->send();
                    }),
                ActionGroup::make([
                    Action::make('preview')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn (ReportSchedule $record) => $record->name)
                        ->modalContent(fn (ReportSchedule $record) => new HtmlString(
                            '<iframe style="width: 100%; height: 70vh; border: 0;" srcdoc="' . e(view('ai-visibility::mail.report', app(ReportBuilder::class)->forSchedule($record))->render()) . '"></iframe>'
                        ))
                        ->modalWidth('5xl')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('No scheduled reports')
            ->emptyStateDescription('Send a weekly or monthly summary to your team or clients.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageReportSchedules::route('/')];
    }
}
