<?php

namespace App\Modules\MasterData\Contracts;

final readonly class CompanyData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $functionalCurrency,
        public string $timezone,
        public string $defaultLocale,
        public string $status,
    ) {}
}
