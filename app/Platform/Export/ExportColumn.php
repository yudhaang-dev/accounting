<?php

namespace App\Platform\Export;

/** A column of an {@see ExportTable}; a numeric column holds decimal strings that Excel keeps as numbers. */
final readonly class ExportColumn
{
    public function __construct(
        public string $label,
        public bool $numeric = false,
    ) {}
}
