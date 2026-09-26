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
     * name; otherwise the first column is used. Commas, semicolons (European Excel)
     * and tabs (Excel "Unicode text") all work as separators. UTF-8 (with or without
     * a BOM), UTF-16 (with or without a BOM) and Windows-1252 files (older Excel
     * exports) are all read as UTF-8.
     *
     * @return array<int, array<string, string>>
     */
    public static function readCsv(string $path, string $defaultColumn): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $contents = static::toUtf8($contents);
        $delimiter = static::delimiter($contents);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        $header = null;
        $aliases = static::COLUMN_ALIASES[$defaultColumn] ?? [];

        // No escape character, so a value ending in "\" (as CsvExport writes it) reads back intact.
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $row = array_map(fn ($value) => static::unguard(trim((string) $value)), $row);

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
     * The separator used by the first line: whichever of comma, semicolon or tab
     * appears most often outside quotes (comma when there are none).
     */
    public static function delimiter(string $contents): string
    {
        $line = '';

        foreach (preg_split('/\r\n|\r|\n/', substr($contents, 0, 65536)) as $candidate) {
            if (trim($candidate) !== '') {
                $line = $candidate;

                break;
            }
        }

        $line = (string) preg_replace('/"(?:[^"]|"")*"/', '', $line);
        $best = ',';
        $most = 0;

        foreach ([',', ';', "\t"] as $delimiter) {
            if (($count = substr_count($line, $delimiter)) > $most) {
                $best = $delimiter;
                $most = $count;
            }
        }

        return $best;
    }

    /**
     * Removes the apostrophe CsvExport adds so spreadsheets don't run a value as a formula.
     */
    protected static function unguard(string $value): string
    {
        return preg_match("/^'[=+\-@\t\r]/", $value) ? substr($value, 1) : $value;
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
        } elseif ($utf16 = static::utf16Order($contents)) {
            $contents = mb_convert_encoding($contents, 'UTF-8', $utf16);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            // Not UTF-8: almost always Windows-1252 (a superset of ISO-8859-1) from Excel.
            // Converted line by line, so a file pasted together from UTF-8 and
            // Windows-1252 parts keeps its UTF-8 lines intact.
            $lines = preg_split('/(?<=\n)/', $contents);

            $contents = implode('', array_map(
                fn ($line) => mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'Windows-1252'),
                $lines,
            ));
        }

        return $contents;
    }

    /**
     * "UTF-16LE" or "UTF-16BE" for UTF-16 text without a byte order mark: mostly
     * Latin text leaves a NUL byte in every other position.
     */
    protected static function utf16Order(string $contents): ?string
    {
        $sample = substr($contents, 0, 4096);
        $pairs = intdiv(strlen($sample), 2);

        if ($pairs < 2 || ! str_contains($sample, "\0")) {
            return null;
        }

        $even = 0;
        $odd = 0;

        for ($i = 0; $i < $pairs * 2; $i += 2) {
            $even += $sample[$i] === "\0" ? 1 : 0;
            $odd += $sample[$i + 1] === "\0" ? 1 : 0;
        }

        if ($odd >= $pairs * 0.6 && $even <= $pairs * 0.05) {
            return 'UTF-16LE';
        }

        if ($even >= $pairs * 0.6 && $odd <= $pairs * 0.05) {
            return 'UTF-16BE';
        }

        return null;
    }

    /**
     * "invalid" (unreadable rows), "truncated" (topic names cut to fit) and
     * "over_limit" (rows past the max_import_rows cap, not read) are only
     * included when non-zero. "paused" counts prompts paused by the
     * active-prompt limit. A "status" column (e.g. "Paused" from our own
     * export) keeps prompts that were not active out of the active set.
     *
     * @param  array<int, string|array{text: string, topic?: ?string, intent?: ?string, status?: ?string, tags?: string|array<string>|null}>  $rows
     * @return array{created: int, skipped: int, paused: int, invalid?: int, truncated?: int, over_limit?: int}
     */
    public function prompts(Brand $brand, array $rows, PromptSource $source = PromptSource::Manual, bool $activate = true): array
    {
        $maxRows = static::maxImportRows();
        $overLimit = 0;

        if ($maxRows !== null && count($rows) > $maxRows) {
            $overLimit = count($rows) - $maxRows;
            $rows = array_slice($rows, 0, $maxRows);
        }

        return DB::transaction(function () use ($brand, $rows, $source, $activate, $overLimit) {
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

                $status = static::enum(PromptStatus::class, $row['status'] ?? null);

                if ($status !== null && $status !== PromptStatus::Active) {
                    // Exported as paused, suggested or rejected: kept that way.
                } elseif (! $activate || ($remaining !== null && $remaining <= 0)) {
                    $status = PromptStatus::Paused;
                    $paused += $activate ? 1 : 0;
                } else {
                    $status = PromptStatus::Active;

                    if ($remaining !== null) {
                        $remaining--;
                    }
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
                    'intent' => static::enum(PromptIntent::class, $row['intent'] ?? null) ?? PromptIntent::Discovery,
                    'tags' => $tags ? array_values($tags) : null,
                    'source' => $source,
                    'status' => $status,
                ]);

                $existing->put($hash, true);
                $created++;
            }

            return compact('created', 'skipped', 'paused') + array_filter(['invalid' => $invalid, 'truncated' => $truncated, 'over_limit' => $overLimit]);
        });
    }

    /**
     * "skipped" counts duplicates only. "too_long" (over 255 characters),
     * "invalid" (unreadable text) and "over_limit" (new keywords left out
     * because the brand reached its keyword limit) are only included when
     * non-zero. When the limit leaves room for some keywords, the first ones
     * are imported; when it leaves none, LimitExceeded is thrown.
     *
     * @param  array<int, string|array{keyword: string, search_volume?: mixed, clicks?: mixed, impressions?: mixed}>  $rows
     * @return array{created: int, skipped: int, too_long?: int, invalid?: int, over_limit?: int}
     */
    public function keywords(Brand $brand, array $rows, KeywordSource $source = KeywordSource::Manual): array
    {
        $invalid = 0;
        $tooLong = 0;
        $skipped = 0;
        $overLimit = 0;
        $new = [];
        // Hashes already stored or seen in this file: a lookup table keeps big files fast.
        $seen = $brand->keywords()->pluck('keyword_hash')->flip()->all();
        $remaining = $this->limits->remainingKeywords($brand);

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

            $hash = Text::hash($row['keyword']);

            if (isset($seen[$hash])) {
                $skipped++;

                continue;
            }

            $seen[$hash] = true;

            if ($remaining !== null && count($new) >= $remaining) {
                $overLimit++;

                continue;
            }

            $new[] = $row;
        }

        DB::transaction(function () use ($brand, $new, $source, $overLimit) {
            // Throws when the brand is already full, so "nothing added" is explained.
            if ($new !== [] || $overLimit > 0) {
                $this->limits->ensureCanAddKeywords($brand, count($new) ?: $overLimit);
            }

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

        return ['created' => count($new), 'skipped' => $skipped]
            + array_filter(['too_long' => $tooLong, 'invalid' => $invalid, 'over_limit' => $overLimit]);
    }

    /**
     * Most rows read from one prompt import (ai-visibility.limits.max_import_rows), or null for no cap.
     */
    public static function maxImportRows(): ?int
    {
        $max = (int) config('ai-visibility.limits.max_import_rows', 5000);

        return $max > 0 ? $max : null;
    }

    /**
     * An enum case from its value ("problem") or its label ("Problem solving").
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    protected static function enum(string $enum, mixed $value): ?\BackedEnum
    {
        $value = mb_strtolower(Text::squish((string) $value));

        if ($value === '') {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if ($case->value === $value || (method_exists($case, 'getLabel') && mb_strtolower($case->getLabel()) === $value)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map([Text::class, 'squish'], preg_split('/\r\n|\r|\n/', (string) $text))));
    }

    /**
     * A whole number from a spreadsheet cell: "12,500", "12.500", "1.2K", "3M",
     * "1.5B", "1e3", "12.5", or a range such as "10-20" (its midpoint). Negative
     * values are not metrics and give null. Halves round to even (12.5 becomes
     * 12), as metrics are whole numbers.
     */
    public static function number(mixed $value): ?int
    {
        $value = mb_strtolower(str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) $value)));

        if (preg_match('/^[-\x{2212}]\d/u', $value)) {
            return null;
        }

        if (preg_match('/^([\d.,]+[kmb]?)[-\x{2013}\x{2014}~]([\d.,]+[kmb]?)$/u', $value, $range)) {
            $low = static::decimal($range[1]);
            $high = static::decimal($range[2]);
            $number = $low !== null && $high !== null ? ($low + $high) / 2 : ($low ?? $high);
        } else {
            $number = static::decimal($value);
        }

        return $number === null ? null : (int) round($number, 0, PHP_ROUND_HALF_EVEN);
    }

    protected static function decimal(string $value): ?float
    {
        if (preg_match('/^\d+(?:\.\d+)?e[+-]?\d+$/', $value)) {
            return (float) $value;
        }

        if (! preg_match('/(\d[\d.,]*)([kmb]?)/', $value, $match)) {
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
        } elseif ($match[2] === '' && preg_match('/^[1-9]\d{0,2}\.\d{3}$/', $digits)) {
            // "12.500" groups thousands European-style; metrics never have three decimals.
            $digits = str_replace('.', '', $digits);
        }

        return (float) $digits * match ($match[2]) {
            'k' => 1_000,
            'm' => 1_000_000,
            'b' => 1_000_000_000,
            default => 1,
        };
    }
}
