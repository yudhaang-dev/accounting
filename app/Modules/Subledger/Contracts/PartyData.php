<?php

namespace App\Modules\Subledger\Contracts;

final readonly class PartyData
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isCustomer,
        public bool $isSupplier,
        public bool $isActive,
    ) {}
}
