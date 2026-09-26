<?php

namespace IsrarMinhas\FilamentAiVisibility\Prompts;

use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;
use IsrarMinhas\FilamentAiVisibility\Models\Usage;
use IsrarMinhas\FilamentAiVisibility\Support\HelperAi;
use IsrarMinhas\FilamentAiVisibility\Support\Instructions;
use IsrarMinhas\FilamentAiVisibility\Support\Text;

/**
 * Proposes topics for a brand's prompts; nothing changes until the user applies it.
 */
class TopicClusterer
{
    /**
     * Prompts sent to the AI in one request.
     */
    public const CHUNK_SIZE = 300;

    /**
     * Most prompts grouped in one go (unassigned prompts are picked first).
     */
    public const MAX_PROMPTS = 1500;

    public function __construct(
        protected HelperAi $helper,
        protected Instructions $instructions,
    ) {}

    /**
     * @return array<int, array{name: string, description: ?string, prompt_ids: array<int>}>
     *
     * @throws \IsrarMinhas\FilamentAiVisibility\Exceptions\HelperUnavailable
     */
    public function propose(Brand $brand): array
    {
        // Prompts without a topic first, so they are always included under the cap.
        $prompts = $brand->prompts()
            ->where('status', '!=', PromptStatus::Rejected)
            ->orderByRaw('topic_id is not null')
            ->orderBy('id')
            ->limit(static::MAX_PROMPTS)
            ->pluck('text', 'id');

        $assigned = [];
        $topics = [];

        // One AI call per chunk; topics with the same name across chunks are merged.
        foreach ($prompts->chunk(static::CHUNK_SIZE) as $chunk) {
            $data = $this->helper->json(Usage::PURPOSE_GENERATION, $this->instructions->render(Instructions::TOPICS, [
                'brand' => $brand->name,
                'description' => $brand->description ?: 'not given',
                'prompts' => $chunk->map(fn ($text, $id) => "{$id}: {$text}")->implode("\n"),
            ]), $brand, maxTokens: 3000);

            foreach ((array) ($data['topics'] ?? []) as $topic) {
                if (! is_array($topic)) {
                    continue;
                }

                $name = mb_substr(Text::squish(is_string($topic['name'] ?? null) ? $topic['name'] : ''), 0, 100);

                if ($name === '') {
                    continue;
                }

                // Only this brand's prompts (from this chunk), each in one topic.
                $ids = collect((array) ($topic['prompt_ids'] ?? []))
                    ->filter(fn ($id) => is_numeric($id))
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $chunk->has($id) && ! isset($assigned[$id]))
                    ->unique()
                    ->values()
                    ->all();

                if (! $ids) {
                    continue;
                }

                foreach ($ids as $id) {
                    $assigned[$id] = true;
                }

                $key = Text::normalize($name);

                if (isset($topics[$key])) {
                    $topics[$key]['prompt_ids'] = [...$topics[$key]['prompt_ids'], ...$ids];
                    $topics[$key]['description'] ??= static::description($topic['description'] ?? null);

                    continue;
                }

                $topics[$key] = ['name' => $name, 'description' => static::description($topic['description'] ?? null), 'prompt_ids' => $ids];
            }
        }

        return array_values($topics);
    }

    /**
     * A short plain-text description, or null when the AI gave none (or not a string).
     */
    protected static function description(mixed $value): ?string
    {
        $value = is_string($value) ? Text::squish($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 500);
    }

    /**
     * @param  array<int, array{name: string, description?: ?string, prompt_ids: array<int>}>  $proposal
     * @return int Prompts assigned.
     */
    public function apply(Brand $brand, array $proposal, bool $replaceExisting = false): int
    {
        $assigned = 0;

        foreach ($proposal as $item) {
            if (blank($item['name'] ?? null) || empty($item['prompt_ids'])) {
                continue;
            }

            $topic = Topic::query()->firstOrCreate(
                ['brand_id' => $brand->getKey(), 'name' => mb_substr(Text::squish((string) $item['name']), 0, 255)],
                ['description' => static::description($item['description'] ?? null), 'source' => 'auto'],
            );

            $assigned += $brand->prompts()
                ->whereKey($item['prompt_ids'])
                ->when(! $replaceExisting, fn ($query) => $query->whereNull('topic_id'))
                ->update(['topic_id' => $topic->getKey()]);
        }

        return $assigned;
    }
}
