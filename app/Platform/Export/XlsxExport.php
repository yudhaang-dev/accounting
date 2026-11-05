<?php

namespace App\Platform\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an {@see ExportTable} as an Excel file. Numbers are numeric cells with a thousands format;
 * text is always a string cell, so a name that starts with `=` is shown, never run as a formula.
 */
final class XlsxExport
{
    public static function download(string $filename, ExportTable $table): StreamedResponse
    {
        return response()->streamDownload(function () use ($table): void {
            // A float is written as text with the `precision` of PHP (14 digits by default), which rounds an amount
            // of a trillion or more with cents; -1 writes the shortest text that reads back as the same number.
            $precision = ini_set('precision', '-1');

            try {
                self::write($table);
            } finally {
                ini_set('precision', $precision === false ? '14' : $precision);
            }
        }, $filename, ['Content-Type' => ExportFormat::Xlsx->value]);
    }

    private static function write(ExportTable $table): void
    {
        $writer = new Writer;
        $writer->openToFile('php://output');

        $bold = new Style(fontBold: true);

        $writer->addRow(new Row([new StringCell($table->title, new Style(fontBold: true, fontSize: 14))]));

        foreach ([...$table->meta, TableExport::generatedLine()] as $line) {
            $writer->addRow(new Row([new StringCell($line, null)]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow(new Row(array_map(fn (ExportColumn $column): Cell => new StringCell($column->label, $bold), $table->columns)));

        foreach ($table->rows as $row) {
            $cells = [];

            foreach ($table->columns as $index => $column) {
                $value = $row->cells[$index] ?? null;
                $style = new Style(fontBold: $row->bold);

                if ($value === null || $value === '') {
                    $cells[] = new StringCell('', null);
                } elseif ($column->numeric && is_numeric($value)) {
                    $cells[] = Cell::fromValue((float) $value, $style->withFormat(str_contains($value, '.') ? '#,##0.00' : '#,##0'));
                } else {
                    $cells[] = new StringCell(($index === 0 ? str_repeat('  ', $row->indent) : '').$value, $style);
                }
            }

            $writer->addRow(new Row($cells));
        }

        $writer->close();

    }
}
