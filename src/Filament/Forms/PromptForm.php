<?php

namespace IsrarMinhas\FilamentAiVisibility\Filament\Forms;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Support\Limits;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

class PromptForm
{
    /**
     * @param  Closure(Get): ?int  $brandId  Resolves the brand the prompt belongs to.
     * @return array<\Filament\Forms\Components\Field>
     */
    public static function fields(Closure $brandId, bool $withBrandSelect = false): array
    {
        return array_values(array_filter([
            $withBrandSelect ? Select::make('brand_id')
                ->label('Brand')
                ->relationship('brand', 'name')
                ->required()
                ->live()
                ->preload() : null,

            Textarea::make('text')
                ->label('Prompt')
                ->required()
                ->rows(3)
                ->maxLength(2000)
                ->helperText('A question a customer might ask an AI assistant. Usually without your brand name.')
                ->rule(fn (Get $get, ?Prompt $record) => function (string $attribute, $value, Closure $fail) use ($get, $record, $brandId) {
                    $brand = $brandId($get);

                    if (! $brand || blank($value)) {
                        return;
                    }

                    $duplicate = Prompt::query()
                        ->where('brand_id', $brand)
                        ->where('text_hash', Text::hash($value))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($duplicate) {
                        $fail('This brand already tracks the same prompt.');
                    }
                })
                ->columnSpanFull(),

            Select::make('topic_id')
                ->label('Topic')
                ->options(fn (Get $get) => Topic::query()->where('brand_id', $brandId($get))->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->createOptionForm([
                    TextInput::make('name')->required()->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data, Get $get) => Topic::query()->firstOrCreate([
                    'brand_id' => $brandId($get),
                    'name' => Text::squish($data['name']),
                ])->getKey()),

            Select::make('intent')
                ->options(PromptIntent::class)
                ->default(PromptIntent::Discovery)
                ->required(),

            Select::make('status')
                ->options(PromptStatus::class)
                ->default(PromptStatus::Active)
                ->required()
                ->rule(fn (Get $get, ?Prompt $record) => function (string $attribute, $value, Closure $fail) use ($get, $record, $brandId) {
                    $status = $value instanceof PromptStatus ? $value : PromptStatus::tryFrom((string) $value);
                    $brand = Brand::query()->find($brandId($get));

                    if ($status !== PromptStatus::Active || ! $brand || $record?->status === PromptStatus::Active) {
                        return;
                    }

                    if (app(Limits::class)->remainingActivePrompts($brand) === 0) {
                        $fail("{$brand->name} already has the maximum number of active prompts. Save it as paused, or pause another prompt.");
                    }
                }),

            TagsInput::make('tags')
                ->placeholder('Add tag'),
        ]));
    }
}
