<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class AccountData
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $code,
        public string $name,
        public AccountClass $class,
        public string $normalBalance,
        public ?string $parentId,
        public bool $isLeaf,
        public bool $isActive,
    ) {}
}
