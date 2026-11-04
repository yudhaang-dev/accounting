<?php

namespace App\Platform\Export;

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Downloads an {@see ExportTable} as CSV, Excel or PDF (ADR-0018, X-FR-02). */
final class TableExport
{
    /**
     * @param  string  $basename  file name without extension
     *
     * @throws ApiException EXPORT_TOO_LARGE when a PDF or Excel file would hold more rows than `export.max_rows`
     */
    public static function download(ExportTable $table, ExportFormat $format, string $basename): StreamedResponse
    {
        if ($format !== ExportFormat::Csv && count($table->rows) > (int) config('export.max_rows')) {
            throw new ApiException(ErrorCode::ExportTooLarge);
        }

        $filename = "{$basename}.{$format->extension()}";

        return match ($format) {
            ExportFormat::Csv => CsvExport::download(
                $filename,
                array_map(fn (ExportColumn $column): string => $column->label, $table->columns),
                array_map(fn (ExportRow $row): array => $row->cells, $table->rows),
            ),
            ExportFormat::Xlsx => XlsxExport::download($filename, $table),
            ExportFormat::Pdf => PdfExport::download($filename, $table),
        };
    }

    /** The line that says when the file was made, for the heading of a PDF or Excel file. */
    public static function generatedLine(): string
    {
        return __('export.generated', ['at' => now()->format('Y-m-d H:i')]);
    }
}
