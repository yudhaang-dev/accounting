<?php

namespace App\Modules\Reporting\Contracts;

/** A period of a report package and its status at the time the package was read. */
final readonly class ReportPackagePeriod
{
    /** @param  string  $status  `open`, `soft_closed` or `closed` */
    public function __construct(
        public string $id,
        public string $name,
        public string $startDate,
        public string $endDate,
        public string $status,
    ) {}
}
