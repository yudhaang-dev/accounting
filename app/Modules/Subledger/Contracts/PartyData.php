<?php

namespace App\Modules\Subledger\Contracts;

final readonly class PartyData
{
    /**
     * @param  list<string>  $roles  keys of the roles the party holds
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $roles,
        public bool $isActive,
        public ?string $counterpartyCompanyId = null,
    ) {}
}
