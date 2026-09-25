<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Keyword;
use IsrarMinhas\FilamentAiVisibility\Models\Prompt;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;

/**
 * Adds prompts and keywords in bulk (pasted lines or CSV), skipping
 * duplicates and respecting limits.
 */
class Importer
{
    public function __construct(
        protected Limits $limits,
    ) {}

    /**
     * Rows from a CSV file. The first row is a header if it contains a known column
     * name; otherwise the first column is used.
     *
     * @return array<int, array<string, string>>
     */
    public static function readCsv(string $path, string $defaultColumn): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $rows = [];
        $header = null;

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $row = array_map(fn ($value) => trim((string) $value), $row);

            if ($row === [''] || $row === []) {
                continue;
            }

            if ($header === null) {
                $lower = array_map(fn ($value) => str_replace(' ', '_', strtolower($value)), $row);

                if (in_array($defaultColumn, $lower, true)) {
                    $header = $lower;

                    continue;
                }

                // No header row: only the first column is used.
                $header = [$defaultColumn];
            }

            $values = [];

            foreach ($header as $index => $column) {
                $values[$column] = $row[$index] ?? '';
            }

            $rows[] = $values;
        }

        fclose($handle);

        return array_values(array_filter($rows, fn ($row) => filled($row[$defaultColumn] ?? null)));
    }

    /**
     * @param  array<int, string|array{text: string, topic?: ?string, intent?: ?string, tags?: string|array<string>|null}>  $rows
     * @return array{created: int, skipped: int, paused: int}
     */
    public function prompts(Brand $brand, array $rows, PromptSource $source = PromptSource::Manual, bool $activate = true): array
    {
        $created = 0;
        $skipped = 0;
        $paused = 0;
        $remaining = $activate ? $this->limits->remainingActivePrompts($brand) : 0;

        $existing = $brand->prompts()->pluck('text_hash')->flip();

        foreach ($rows as $row) {
            $row = is_string($row) ? ['text' => $row] : $row;
            $text = Text::squish($row['text'] ?? '');

            if ($text === '' || $existing->has($hash = Text::hash($text))) {
                $skipped++;

                continue;
            }

            $status = PromptStatus::Active;

            if (! $activate || ($remaining !== null && $remaining <= 0)) {
                $status = PromptStatus::Paused;
                $paused += $activate ? 1 : 0;
            } elseif ($remaining !== null) {
                $remaining--;
            }

            $topicId = filled($row['topic'] ?? null)
                ? Topic::query()->firstOrCreate(['brand_id' => $brand->getKey(), 'name' => Text::squish($row['topic'])])->getKey()
                : null;

            $tags = $row['tags'] ?? null;
            $tags = is_string($tags) ? array_filter(array_map('trim', preg_split('/[,;|]/', $tags))) : $tags;

            $brand->prompts()->create([
                'text' => $text,
                'topic_id' => $topicId,
                'intent' => PromptIntent::tryFrom(strtolower((string) ($row['intent'] ?? ''))) ?? PromptIntent::Discovery,
                'tags' => $tags ? array_values($tags) : null,
                'source' => $source,
                'status' => $status,
            ]);

            $existing->put($hash, true);
            $created++;
        }

        return compact('created', 'skipped', 'paused');
    }

    /**
     * @param  array<int, string|array{keyword: string, search_volume?: mixed, clicks?: mixed, impressions?: mixed}>  $rows
     * @return array{created: int, skipped: int}
     */
    public function keywords(Brand $brand, array $rows, KeywordSource $source = KeywordSource::Manual): array
    {
        $rows = array_values(array_filter(array_map(
            fn ($row) => is_string($row) ? ['keyword' => $row] : $row,
            $rows,
        ), fn ($row) => filled(Text::squish($row['keyword'] ?? ''))));

        $existing = $brand->keywords()->pluck('keyword_hash')->flip();
        $new = array_filter($rows, fn ($row) => ! $existing->has(Text::hash($row['keyword'])));
        $new = collect($new)->unique(fn ($row) => Text::hash($row['keyword']))->values();

        $this->limits->ensureCanAddKeywords($brand, $new->count());

        foreach ($new as $row) {
            $brand->keywords()->create([
                'keyword' => $row['keyword'],
                'source' => $source,
                'search_volume' => static::number($row['search_volume'] ?? $row['volume'] ?? null),
                'clicks' => static::number($row['clicks'] ?? null),
                'impressions' => static::number($row['impressions'] ?? null),
            ]);
        }

        return ['created' => $new->count(), 'skipped' => count($rows) - $new->count()];
    }

    /**
     * @return array<string>
     */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map([Text::class, 'squish'], preg_split('/\r\n|\r|\n/', (string) $text))));
    }

    protected static function number(mixed $value): ?int
    {
        $value = preg_replace('/[^\d]/', '', (string) $value);

        return $value === '' ? null : (int) $value;
    }
}
