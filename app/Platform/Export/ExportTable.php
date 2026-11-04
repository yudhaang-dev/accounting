<?php

namespace App\Platform\Export;

/**
 * A report as every export format reads it (ADR-0018): a heading, the lines that say what the numbers
 * are (company, period, currency, filters, notices), and rows under column headers.
 */
final readonly class ExportTable
{
    /**
     * @param  list<string>  $meta
     * @param  list<ExportColumn>  $columns
     * @param  list<ExportRow>  $rows
     */
    public function __construct(
        public string $title,
        public array $meta,
        public array $columns,
        public array $rows,
    ) {}
}
