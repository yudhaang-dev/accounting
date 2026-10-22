<?php

namespace App\Modules\Reporting\Contracts;

/** An account of a company as a consolidation may map it: its class and whether it takes postings. */
final readonly class ReportPackageAccount
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $class,
        public bool $isPostable,
    ) {}
}
