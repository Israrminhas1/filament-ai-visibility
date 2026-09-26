<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\ResultResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\RunResource;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

class ViewResult extends ViewRecord
{
    protected static string $resource = ResultResource::class;

    /**
     * @var array<string, ?string>
     */
    protected array $neighbours = [];

    public function getTitle(): string | Htmlable
    {
        return Str::limit((string) ($this->result()->prompt?->text ?? 'Answer'), 160);
    }

    public function getSubheading(): ?string
    {
        $result = $this->result();

        return collect([
            $result->brand?->name,
            ResultResource::engineLabel($result->engine),
            $result->model,
            'sample ' . $result->sample,
            $result->ran_at?->toDayDateTimeString(),
            $result->status->getLabel(),
        ])->filter(fn ($part) => filled($part))->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return 'Answer';
    }

    protected function getHeaderActions(): array
    {
        $result = $this->result();

        return [
            Action::make('previous')
                ->label('Previous')
                ->tooltip('Previous answer in this run')
                ->icon('heroicon-m-chevron-left')
                ->color('gray')
                ->url(fn () => $this->neighbourUrl('previous'))
                ->visible(fn () => filled($this->neighbourUrl('previous'))),
            Action::make('next')
                ->label('Next')
                ->tooltip('Next answer in this run')
                ->icon('heroicon-m-chevron-right')
                ->iconPosition('after')
                ->color('gray')
                ->url(fn () => $this->neighbourUrl('next'))
                ->visible(fn () => filled($this->neighbourUrl('next'))),
            ActionGroup::make([
                Action::make('openPrompt')
                    ->label('Open prompt')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn () => $result->prompt ? AiVisibilityPlugin::pageUrl(PromptResource::class, 'view', ['record' => $result->prompt]) : null)
                    ->visible(fn () => $result->prompt && filled(AiVisibilityPlugin::pageUrl(PromptResource::class, 'view', ['record' => $result->prompt]))),
                Action::make('openRun')
                    ->label('Open run')
                    ->icon('heroicon-o-play-circle')
                    ->url(fn () => $result->run_id ? AiVisibilityPlugin::pageUrl(RunResource::class, 'view', ['record' => $result->run_id]) : null)
                    ->visible(fn () => $result->run_id && filled(AiVisibilityPlugin::pageUrl(RunResource::class, 'view', ['record' => $result->run_id]))),
            ])
                ->label('Open')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->button(),
        ];
    }

    protected function result(): Result
    {
        /** @var Result */
        return $this->getRecord();
    }

    /**
     * The previous or next answer in the same run (one indexed query each).
     */
    protected function neighbourUrl(string $direction): ?string
    {
        if (array_key_exists($direction, $this->neighbours)) {
            return $this->neighbours[$direction];
        }

        $result = $this->result();

        // Answers without a run are not a sequence: stepping would walk every run-less answer.
        if ($result->run_id === null) {
            return $this->neighbours[$direction] = null;
        }

        $id = Result::query()
            ->where('run_id', $result->run_id)
            ->where('id', $direction === 'next' ? '>' : '<', $result->getKey())
            ->orderBy('id', $direction === 'next' ? 'asc' : 'desc')
            ->value('id');

        return $this->neighbours[$direction] = $id ? AiVisibilityPlugin::pageUrl(ResultResource::class, 'view', ['record' => $id]) : null;
    }
}
