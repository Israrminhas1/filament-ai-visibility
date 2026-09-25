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

            // Excel needs the BOM to read UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, escape: '\\');

            foreach ($rows as $row) {
                fputcsv($out, array_map([static::class, 'cell'], $row), escape: '\\');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
