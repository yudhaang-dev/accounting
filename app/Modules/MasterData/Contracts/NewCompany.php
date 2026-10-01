<?php

namespace App\Modules\MasterData\Contracts;

final readonly class NewCompany
{
    public function __construct(
        public string $name,
        public string $functionalCurrency,
        public string $timezone,
        public string $defaultLocale,
    ) {}
}
