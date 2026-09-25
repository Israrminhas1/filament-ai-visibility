<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Illuminate\Support\Collection;
use IsrarMinhas\FilamentAiVisibility\Enums\ResultStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Result;

/**
 * Each engine's answers to one prompt over time, with what changed between
 * consecutive answers: brand mentioned or not, position, competitors and sources.
 */
class PromptHistory
{
    /**
     * @return Collection<string, Collection<int, array<string, mixed>>> engine => entries, newest first
     */
    public function forPrompt(Prompt $prompt, int $perEngine = 20): Collection
    {
        $results = Result::query()
            ->where('prompt_id', $prompt->getKey())
            ->where('status', ResultStatus::Success)
            ->with(['mentions.competitor', 'citations'])
            ->orderBy('ran_at')
            ->get()
            ->groupBy('engine');

        return $results->map(function (Collection $answers) use ($perEngine) {
            $entries = collect();
            $previous = null;

            foreach ($answers as $result) {
                $competitors = $result->mentions->where('subject_type', 'competitor')->map(fn ($m) => $m->competitor?->name ?? $m->name_matched)->unique()->values();
                $sources = $result->citations->pluck('domain')->unique()->values();

                $entry = [
                    'result' => $result,
                    'mentioned' => $result->brand_mentioned,
                    'position' => $result->brand_position,
                    'competitors' => $competitors->all(),
                    'sources' => $sources->all(),
                    'changes' => [],
                ];

                if ($previous) {
                    $entry['changes'] = $this->changes($previous, $entry);
                }

                $entries->push($entry);
                $previous = $entry;
            }

            return $entries->reverse()->take($perEngine)->values();
        })->sortKeys();
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    protected function changes(array $before, array $after): array
    {
        $changes = [];

        if ($before['mentioned'] !== $after['mentioned']) {
            $changes[] = $after['mentioned']
                ? ['type' => 'good', 'text' => 'Brand now mentioned']
                : ['type' => 'bad', 'text' => 'Brand no longer mentioned'];
        } elseif ($after['mentioned'] && $before['position'] !== $after['position']) {
            $changes[] = $after['position'] < $before['position']
                ? ['type' => 'good', 'text' => "Moved up from #{$before['position']} to #{$after['position']}"]
                : ['type' => 'bad', 'text' => "Moved down from #{$before['position']} to #{$after['position']}"];
        }

        foreach (array_diff($after['competitors'], $before['competitors']) as $name) {
            $changes[] = ['type' => 'bad', 'text' => "{$name} now mentioned"];
        }

        foreach (array_diff($before['competitors'], $after['competitors']) as $name) {
            $changes[] = ['type' => 'good', 'text' => "{$name} no longer mentioned"];
        }

        foreach (array_diff($after['sources'], $before['sources']) as $domain) {
            $changes[] = ['type' => 'neutral', 'text' => "New source: {$domain}"];
        }

        foreach (array_diff($before['sources'], $after['sources']) as $domain) {
            $changes[] = ['type' => 'neutral', 'text' => "Source dropped: {$domain}"];
        }

        return $changes;
    }
}
