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
        $prompts = $brand->prompts()
            ->where('status', '!=', PromptStatus::Rejected)
            ->orderBy('id')
            ->limit(300)
            ->pluck('text', 'id');

        if ($prompts->isEmpty()) {
            return [];
        }

        $data = $this->helper->json(Usage::PURPOSE_GENERATION, $this->instructions->render(Instructions::TOPICS, [
            'brand' => $brand->name,
            'description' => $brand->description ?: 'not given',
            'prompts' => $prompts->map(fn ($text, $id) => "{$id}: {$text}")->implode("\n"),
        ]), $brand, maxTokens: 3000);

        $assigned = [];
        $topics = [];

        foreach ((array) ($data['topics'] ?? []) as $topic) {
            $name = Text::squish(is_array($topic) ? (string) ($topic['name'] ?? '') : '');

            if ($name === '') {
                continue;
            }

            // Only this brand's prompts, each in one topic.
            $ids = collect((array) ($topic['prompt_ids'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $prompts->has($id) && ! isset($assigned[$id]))
                ->unique()
                ->values()
                ->all();

            foreach ($ids as $id) {
                $assigned[$id] = true;
            }

            if ($ids) {
                $topics[] = ['name' => mb_substr($name, 0, 100), 'description' => $topic['description'] ?? null, 'prompt_ids' => $ids];
            }
        }

        return $topics;
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
                ['brand_id' => $brand->getKey(), 'name' => Text::squish($item['name'])],
                ['description' => $item['description'] ?? null, 'source' => 'auto'],
            );

            $assigned += $brand->prompts()
                ->whereKey($item['prompt_ids'])
                ->when(! $replaceExisting, fn ($query) => $query->whereNull('topic_id'))
                ->update(['topic_id' => $topic->getKey()]);
        }

        return $assigned;
    }
}
