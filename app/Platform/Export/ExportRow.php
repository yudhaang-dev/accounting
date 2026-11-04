<?php

namespace App\Platform\Export;

/**
 * One row of an {@see ExportTable}: a cell per column, `null` for an empty one. A cell of a numeric
 * column is a decimal string such as `-1500000.50`.
 */
final readonly class ExportRow
{
    /**
     * @param  list<string|null>  $cells
     */
    public function __construct(
        public array $cells,
        public bool $bold = false,
        public bool $muted = false,
        public int $indent = 0,
    ) {}
}
