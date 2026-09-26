<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use IsrarMinhas\FilamentAiVisibility\AiVisibilityPlugin;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable;
use IsrarMinhas\FilamentAiVisibility\Filament\Resources\PromptResource;
use IsrarMinhas\FilamentAiVisibility\Filament\Tables\PromptTable;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Prompts\PromptGenerator;
use IsrarMinhas\FilamentAiVisibility\Prompts\TopicClusterer;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;

class GeneratePromptsAction
{
    /**
     * @param  (Closure(): ?Brand)|null  $brand  Fixed brand; otherwise a brand select is shown.
     */
    public static function make(?Closure $brand = null): Action
    {
        return Action::make('generatePrompts')
            ->label('Generate prompts')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalDescription('Writes realistic questions from the brand\'s keywords (or its profile), then filters out weak ones. New prompts are saved as "Suggested" for you to review and activate.')
            ->schema(static::fields($brand))
            ->action(fn (array $data) => static::run($brand ? $brand() : Brand::query()->findOrFail($data['brand_id']), $data));
    }

    /**
     * "Generate prompts from selected" on the keywords table.
     */
    public static function fromKeywords(): BulkAction
    {
        return BulkAction::make('generateFromKeywords')
            ->label('Generate prompts from selected')
            ->icon('heroicon-o-sparkles')
            ->schema(static::fields(null, withBrand: false, withKeywordToggle: false))
            ->action(function (Collection $records, array $data) {
                foreach ($records->groupBy('brand_id') as $keywords) {
                    static::run($keywords->first()->brand, [...$data, 'use_keywords' => true], $keywords->modelKeys());
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @return array<\Filament\Forms\Components\Field>
     */
    protected static function fields(?Closure $brand, bool $withBrand = true, bool $withKeywordToggle = true): array
    {
        $settings = app(Settings::class);

        return array_values(array_filter([
            $withBrand && ! $brand ? Select::make('brand_id')->label('Brand')->options(PromptTable::brandOptions())->required()->live() : null,
            TextInput::make('count')
                ->label('How many')
                ->numeric()
                ->minValue(1)
                ->maxValue(50)
                ->default((int) $settings->get('generation.count', 10))
                ->required(),
            CheckboxList::make('intents')
                ->options(PromptIntent::class)
                ->default((array) $settings->get('generation.intents', []))
                ->columns(2)
                ->required(),
            TextInput::make('persona')
                ->placeholder('e.g. owner of a 10-person marketing agency')
                ->default($settings->get('generation.persona')),
            Select::make('topic_id')
                ->label('Topic')
                ->placeholder('Any topic')
                ->options(fn (Get $get) => Topic::query()
                    ->where('brand_id', $brand ? $brand()?->getKey() : $get('brand_id'))
                    ->orderBy('name')
                    ->pluck('name', 'id')),
            $withKeywordToggle ? Toggle::make('use_keywords')
                ->label('Base them on the brand\'s keywords')
                ->default(true) : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>|null  $keywordIds
     */
    public static function run(Brand $brand, array $data, ?array $keywordIds = null): void
    {
        try {
            $result = app(PromptGenerator::class)->generate(
                brand: $brand,
                count: (int) $data['count'],
                intents: array_map(fn ($i) => $i instanceof PromptIntent ? $i->value : $i, (array) ($data['intents'] ?? [])),
                persona: $data['persona'] ?? null,
                // Only a topic of this brand, whatever id was submitted.
                topic: filled($data['topic_id'] ?? null) ? Topic::query()->where('brand_id', $brand->getKey())->find($data['topic_id']) : null,
                keywordIds: $keywordIds,
                useKeywords: (bool) ($data['use_keywords'] ?? true),
            );
        } catch (HelperUnavailable $e) {
            Notification::make()->title('Could not generate prompts')->body($e->getMessage())->warning()->persistent()->send();

            return;
        }

        $notification = Notification::make()
            ->title("{$result['suggested']->count()} prompts suggested for {$brand->name}")
            ->body($result['rejected']->count() ? "{$result['rejected']->count()} weak ideas were filtered out (kept as Rejected, with the reason)." : null)
            ->success();

        if ($url = AiVisibilityPlugin::pageUrl(PromptResource::class, 'index', ['filters' => ['status' => ['values' => ['suggested']]]])) {
            $notification->actions([Action::make('review')->label('Review suggestions')->url($url)->button()]);
        }

        $notification->send();
    }

    /**
     * Propose topics for the brand's prompts; the user can rename or drop them before applying.
     */
    public static function organiseTopics(Closure $brand): Action
    {
        return Action::make('organiseTopics')
            ->label('Organise into topics')
            ->icon('heroicon-o-folder')
            ->color('gray')
            ->modalDescription('The AI helper proposes topics for this brand\'s prompts (up to ' . number_format(TopicClusterer::MAX_PROMPTS) . ' at a time, those without a topic first). Rename or remove any, then apply.')
            ->mountUsing(function (?Schema $schema) use ($brand) {
                try {
                    $proposal = app(TopicClusterer::class)->propose($brand());
                } catch (HelperUnavailable $e) {
                    Notification::make()->title('Could not group prompts')->body($e->getMessage())->warning()->send();
                    $proposal = [];
                }

                $schema?->fill([
                    'topics' => collect($proposal)->map(fn ($topic) => [
                        'name' => $topic['name'],
                        'prompt_ids' => implode(',', $topic['prompt_ids']),
                        'count' => count($topic['prompt_ids']),
                    ])->all(),
                    'replace' => false,
                ]);
            })
            ->schema([
                \Filament\Forms\Components\Repeater::make('topics')
                    ->hiddenLabel()
                    ->addable(false)
                    ->reorderable(false)
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->required()->columnSpan(2),
                        TextInput::make('count')->label('Prompts')->disabled()->dehydrated(false),
                        \Filament\Forms\Components\Hidden::make('prompt_ids'),
                    ]),
                Toggle::make('replace')->label('Also move prompts that already have a topic'),
            ])
            ->action(function (array $data) use ($brand) {
                $proposal = collect($data['topics'] ?? [])->map(fn ($topic) => [
                    'name' => $topic['name'],
                    'prompt_ids' => array_map('intval', array_filter(explode(',', (string) $topic['prompt_ids']))),
                ])->all();

                $assigned = app(TopicClusterer::class)->apply($brand(), $proposal, (bool) ($data['replace'] ?? false));

                Notification::make()->title("{$assigned} prompts assigned to " . count($proposal) . ' topics')->success()->send();
            });
    }
}
