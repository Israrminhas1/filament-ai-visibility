<?php

namespace IsrarMinhas\FilamentAiVisibility\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows as a CSV download without loading them all into memory.
 */
class CsvExport
{
    /**
     * @param  array<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            static::write($out, $headers, $rows);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Writes the CSV to an open stream. No escape character is used, so a value
     * ending in "\" stays in its own cell (Importer reads it back the same way).
     *
     * @param  resource  $out
     * @param  array<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function write($out, array $headers, iterable $rows): void
    {
        // Excel needs the BOM to read UTF-8 correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map([static::class, 'cell'], $row), escape: '');
        }
    }

    /**
     * Neutralise values a spreadsheet would run as a formula.
     */
    public static function cell(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
