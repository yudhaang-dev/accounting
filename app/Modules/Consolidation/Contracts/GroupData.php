<?php

namespace App\Modules\Consolidation\Contracts;

final readonly class GroupData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $parentCompanyId,
        public string $presentationCurrency,
        public int $lockVersion = 1,
        public string $packagePolicy = 'draft',
        public string $eliminationTolerance = '0',
    ) {}
}
