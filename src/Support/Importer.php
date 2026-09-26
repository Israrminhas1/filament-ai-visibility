<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Illuminate\Support\Facades\DB;
use IsrarMinhas\FilamentAiVisibility\Enums\KeywordSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptIntent;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptSource;
use IsrarMinhas\FilamentAiVisibility\Enums\PromptStatus;
use IsrarMinhas\FilamentAiVisibility\Models\Brand;
use IsrarMinhas\FilamentAiVisibility\Models\Topic;

/**
 * Adds prompts and keywords in bulk (pasted lines or CSV), skipping
 * duplicates and respecting limits. Each import runs in a transaction,
 * so a failure never leaves half a file imported.
 */
class Importer
{
    /**
     * Longest keyword or topic name the database column holds.
     */
    public const MAX_LENGTH = 255;

    /**
     * Other header names accepted for the main column (e.g. our own prompt export uses "Prompt").
     */
    protected const COLUMN_ALIASES = [
        'text' => ['prompt', 'prompts', 'question', 'questions'],
        'keyword' => ['keywords', 'query', 'queries', 'top_queries', 'search_term', 'term'],
    ];

    public function __construct(
        protected Limits $limits,
    ) {}

    /**
     * Rows from a CSV file. The first row is a header if it contains a known column
     * name; otherwise the first column is used. UTF-8 (with or without a BOM),
     * UTF-16 and Windows-1252 files (older Excel exports) are all read as UTF-8.
     *
     * @return array<int, array<string, string>>
     */
    public static function readCsv(string $path, string $defaultColumn): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, static::toUtf8($contents));
        rewind($handle);

        $rows = [];
        $header = null;
        $aliases = static::COLUMN_ALIASES[$defaultColumn] ?? [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $row = array_map(fn ($value) => trim((string) $value), $row);

            if ($row === [''] || $row === []) {
                continue;
            }

            if ($header === null) {
                $lower = array_map(fn ($value) => str_replace(' ', '_', mb_strtolower($value)), $row);
                $lower = array_map(fn ($value) => in_array($value, $aliases, true) && ! in_array($defaultColumn, $lower, true) ? $defaultColumn : $value, $lower);

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
     * File contents as UTF-8 without a byte order mark.
     */
    public static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        } elseif (str_starts_with($contents, "\xFF\xFE")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            // Not UTF-8: almost always Windows-1252 (a superset of ISO-8859-1) from Excel.
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /**
     * "invalid" (unreadable rows) and "truncated" (topic names cut to fit) are
     * only included when non-zero.
     *
     * @param  array<int, string|array{text: string, topic?: ?string, intent?: ?string, tags?: string|array<string>|null}>  $rows
     * @return array{created: int, skipped: int, paused: int, invalid?: int, truncated?: int}
     */
    public function prompts(Brand $brand, array $rows, PromptSource $source = PromptSource::Manual, bool $activate = true): array
    {
        return DB::transaction(function () use ($brand, $rows, $source, $activate) {
            $created = 0;
            $skipped = 0;
            $paused = 0;
            $invalid = 0;
            $truncated = 0;
            $remaining = $activate ? $this->limits->remainingActivePrompts($brand) : 0;

            $existing = $brand->prompts()->pluck('text_hash')->flip();

            foreach ($rows as $row) {
                $row = is_string($row) ? ['text' => $row] : $row;
                $raw = (string) ($row['text'] ?? '');

                if (! mb_check_encoding($raw, 'UTF-8')) {
                    $invalid++;

                    continue;
                }

                $text = Text::squish($raw);

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

                $topicId = null;

                if (filled($row['topic'] ?? null) && mb_check_encoding((string) $row['topic'], 'UTF-8')) {
                    $topic = Text::squish($row['topic']);

                    if (mb_strlen($topic) > static::MAX_LENGTH) {
                        $topic = rtrim(mb_substr($topic, 0, static::MAX_LENGTH));
                        $truncated++;
                    }

                    $topicId = Topic::query()->firstOrCreate(['brand_id' => $brand->getKey(), 'name' => $topic])->getKey();
                }

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

            return compact('created', 'skipped', 'paused') + array_filter(compact('invalid', 'truncated'));
        });
    }

    /**
     * "skipped" counts duplicates only. "too_long" (over 255 characters) and
     * "invalid" (unreadable text) are only included when non-zero.
     *
     * @param  array<int, string|array{keyword: string, search_volume?: mixed, clicks?: mixed, impressions?: mixed}>  $rows
     * @return array{created: int, skipped: int, too_long?: int, invalid?: int}
     */
    public function keywords(Brand $brand, array $rows, KeywordSource $source = KeywordSource::Manual): array
    {
        $invalid = 0;
        $tooLong = 0;
        $valid = [];

        foreach ($rows as $row) {
            $row = is_string($row) ? ['keyword' => $row] : $row;
            $raw = (string) ($row['keyword'] ?? '');

            if (! mb_check_encoding($raw, 'UTF-8')) {
                $invalid++;

                continue;
            }

            $row['keyword'] = Text::squish($raw);

            if ($row['keyword'] === '') {
                continue;
            }

            if (mb_strlen($row['keyword']) > static::MAX_LENGTH) {
                $tooLong++;

                continue;
            }

            $valid[] = $row;
        }

        $existing = $brand->keywords()->pluck('keyword_hash')->flip();
        $new = array_filter($valid, fn ($row) => ! $existing->has(Text::hash($row['keyword'])));
        $new = collect($new)->unique(fn ($row) => Text::hash($row['keyword']))->values();

        DB::transaction(function () use ($brand, $new, $source) {
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
        });

        return ['created' => $new->count(), 'skipped' => count($valid) - $new->count()]
            + array_filter(['too_long' => $tooLong, 'invalid' => $invalid]);
    }

    /**
     * @return array<string>
     */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map([Text::class, 'squish'], preg_split('/\r\n|\r|\n/', (string) $text))));
    }

    /**
     * A whole number from a spreadsheet cell: "12,500", "1.2K", "3M", "12.5".
     * Halves round to even (12.5 becomes 12), as metrics are whole numbers.
     */
    public static function number(mixed $value): ?int
    {
        $value = mb_strtolower(str_replace([' ', "\u{00A0}"], '', trim((string) $value)));

        if (! preg_match('/(\d[\d.,]*)([km]?)/', $value, $match)) {
            return null;
        }

        $digits = rtrim($match[1], '.,');
        $lastComma = strrpos($digits, ',');
        $lastDot = strrpos($digits, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Both separators: whichever comes last is the decimal point.
            $digits = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $digits)
                : str_replace(',', '', $digits);
        } elseif ($lastComma !== false) {
            // "12,500" groups thousands; "12,5" is a decimal comma.
            $digits = preg_match('/^\d{1,3}(,\d{3})+$/', $digits) ? str_replace(',', '', $digits) : str_replace(',', '.', $digits);
        } elseif (substr_count($digits, '.') > 1) {
            // "1.200.000" groups thousands with dots.
            $digits = str_replace('.', '', $digits);
        }

        $number = (float) $digits * match ($match[2]) {
            'k' => 1_000,
            'm' => 1_000_000,
            default => 1,
        };

        return (int) round($number, 0, PHP_ROUND_HALF_EVEN);
    }
}
