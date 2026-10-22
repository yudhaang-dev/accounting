<?php

namespace App\Modules\Consolidation\Contracts;

final readonly class PerimeterMemberData
{
    /** @param  string  $status  `pending`, `approved` or `ended` */
    public function __construct(
        public string $id,
        public string $groupId,
        public string $companyId,
        public string $status,
    ) {}
}
