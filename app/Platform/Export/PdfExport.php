<?php

namespace App\Platform\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Renders an {@see ExportTable} as a PDF with its heading and column headers on every page. */
final class PdfExport
{
    public static function download(string $filename, ExportTable $table): StreamedResponse
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', base_path());

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('export.table', [
            'table' => $table,
            'generated' => TableExport::generatedLine(),
            'format' => self::number(...),
        ])->render());
        $pdf->setPaper('A4', count($table->columns) > 5 ? 'landscape' : 'portrait');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, __('export.page'), $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8);

        $content = (string) $pdf->output();

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => ExportFormat::Pdf->value]);
    }

    /** A decimal string grouped the way the screen shows it: `1.500.000,50` in Indonesian, `1,500,000.50` otherwise. */
    public static function number(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        $indonesian = app()->getLocale() === 'id';
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, null);
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $digits = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', $indonesian ? '.' : ',', ltrim($whole, '-'));

        return $sign.$digits.($fraction === null ? '' : ($indonesian ? ',' : '.').$fraction);
    }
}
