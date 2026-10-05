<?php

namespace App\Modules\Subledger\Contracts;

final readonly class CashAccountData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $currency,
        public string $accountId,
        public bool $isActive,
    ) {}
}
