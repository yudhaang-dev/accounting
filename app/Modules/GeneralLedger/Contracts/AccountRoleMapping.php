<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The account playing a role from an effective date, as one version of the mapping (GL-FR-10).
 */
final readonly class AccountRoleMapping
{
    /** @param  string  $effectiveDate  `YYYY-MM-DD` */
    public function __construct(
        public AccountRole $role,
        public string $accountId,
        public int $version,
        public string $effectiveDate,
    ) {}
}
