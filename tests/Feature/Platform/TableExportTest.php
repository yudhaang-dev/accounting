<?php

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Export\ExportColumn;
use App\Platform\Export\ExportFormat;
use App\Platform\Export\ExportRow;
use App\Platform\Export\ExportTable;
use App\Platform\Export\PdfExport;
use App\Platform\Export\TableExport;

function teTable(int $rows = 3): ExportTable
{
    return new ExportTable(
        'Laba Rugi',
        ['PT Contoh', 'Periode 2026-01-01 – 2026-01-31', 'Mata uang: IDR'],
        [new ExportColumn('Baris'), new ExportColumn('Periode', numeric: true), new ExportColumn('Tahun lalu', numeric: true)],
        [
            new ExportRow(['Pendapatan', '1500000.50', '-20.00'], bold: true),
            new ExportRow(['=SUM(A1)', '10', null], indent: 1),
            new ExportRow(['Beban – Pemasaran & Iklan', '7.25', '0.00'], muted: true),
            ...array_map(fn (int $i): ExportRow => new ExportRow(["Akun {$i}", (string) $i, '1.00']), $rows > 3 ? range(1, $rows - 3) : []),
        ],
    );
}

function teContent(ExportTable $table, ExportFormat $format): string
{
    ob_start();
    TableExport::download($table, $format, 'report')->sendContent();

    return (string) ob_get_clean();
}

/** @return list<string> the XML of the sheets in an Excel file */
function teSheets(string $xlsx): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $xlsx);
    $zip = new ZipArchive;
    $zip->open($path);
    $sheets = [(string) $zip->getFromName('xl/worksheets/sheet1.xml')];
    $zip->close();
    unlink($path);

    return $sheets;
}

describe('table export (ADR-0018, X-FR-02)', function () {
    it('picks the format from the Accept header', function () {
        $wanted = fn (string $accept): ?ExportFormat => ExportFormat::wanted(request()->duplicate(server: ['HTTP_ACCEPT' => $accept]));

        expect($wanted('text/csv'))->toBe(ExportFormat::Csv)
            ->and($wanted('application/pdf'))->toBe(ExportFormat::Pdf)
            ->and($wanted('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBe(ExportFormat::Xlsx)
            ->and($wanted('application/json'))->toBeNull();
    });

    it('writes numbers as numeric cells and text as strings that never run as a formula', function () {
        [$sheet] = teSheets(teContent(teTable(), ExportFormat::Xlsx));

        expect($sheet)->toContain('<v>1500000.5</v>')->toContain('<v>-20</v>')
            ->and($sheet)->toContain('Laba Rugi')->toContain('PT Contoh')->toContain('Beban – Pemasaran &amp; Iklan')
            ->and($sheet)->toContain('=SUM(A1)')
            ->and($sheet)->not->toContain('<f>');
    });

    it('renders a PDF, and one that spans pages repeats nothing it should not', function () {
        $short = teContent(teTable(), ExportFormat::Pdf);
        $long = teContent(teTable(150), ExportFormat::Pdf);
        $pages = fn (string $pdf): int => substr_count($pdf, '/Type /Page
');

        expect($short)->toStartWith('%PDF')
            ->and($pages($short))->toBe(1)
            ->and($pages($long))->toBeGreaterThan(1);
    });

    it('prints a wide table in landscape', function () {
        $columns = array_map(fn (int $i): ExportColumn => new ExportColumn("K{$i}", numeric: true), range(1, 6));
        $wide = new ExportTable('Lebar', [], $columns, [new ExportRow(array_fill(0, 6, '1.00'))]);

        expect(teContent($wide, ExportFormat::Pdf))->toMatch('/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+841\.\d+\s+595\.\d+\s*\]/')
            ->and(teContent(teTable(), ExportFormat::Pdf))->toMatch('/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595\.\d+\s+841\.\d+\s*\]/');
    });

    it('refuses a PDF or Excel file over the row limit, and still streams the CSV', function () {
        config(['export.max_rows' => 5]);
        $table = teTable(6);

        foreach ([ExportFormat::Pdf, ExportFormat::Xlsx] as $format) {
            try {
                TableExport::download($table, $format, 'report');
                $refused = null;
            } catch (ApiException $e) {
                $refused = $e->errorCode;
            }

            expect($refused)->toBe(ErrorCode::ExportTooLarge);
        }

        expect(teContent($table, ExportFormat::Csv))->toContain('Baris,Periode,"Tahun lalu"');
    });

    it('groups numbers the way the screen shows them', function () {
        app()->setLocale('id');
        expect(PdfExport::number('1500000.50'))->toBe('1.500.000,50')
            ->and(PdfExport::number('-1234'))->toBe('-1.234')
            ->and(PdfExport::number('12.5'))->toBe('12,5');

        app()->setLocale('en');
        expect(PdfExport::number('-1500000.50'))->toBe('-1,500,000.50')
            ->and(PdfExport::number('abc'))->toBe('abc');
    });
});
