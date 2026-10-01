<?php

namespace App\Platform\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows as a CSV download (X-FR-02). Text cells that a spreadsheet would read as a formula
 * are prefixed with an apostrophe; numbers are written as they are.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(self::cell(...), $header), escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(string|int|null $value): string
    {
        $text = (string) $value;

        if ($text !== '' && ! is_numeric($text) && str_contains("=+-@\t\r", $text[0])) {
            return "'".$text;
        }

        return $text;
    }
}
