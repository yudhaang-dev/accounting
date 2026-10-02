<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The versioned mapping of ledger roles to accounts, per company (GL-FR-10, X-AC-03). A change adds
 * a version and never rewrites an earlier one, so a posting can name the version it used.
 */
interface AccountRoles
{
    /**
     * The mapping in force on `$asOf` (the latest version when null), or null when the role is not mapped.
     *
     * @param  string|null  $asOf  `YYYY-MM-DD`
     */
    public function current(string $companyId, AccountRole $role, ?string $asOf = null): ?AccountRoleMapping;

    /**
     * The latest mapping of every mapped role.
     *
     * @return array<string, AccountRoleMapping> by role key
     */
    public function all(string $companyId): array;

    /**
     * The mapping to use for a posting: it must exist and its account must still be a valid target.
     *
     * Fails with ApiException MAPPING_REQUIRED, saying which role and why.
     *
     * @param  string|null  $asOf  `YYYY-MM-DD`
     */
    public function require(string $companyId, AccountRole $role, ?string $asOf = null): AccountRoleMapping;

    /**
     * Those of the roles that are not mapped.
     *
     * @param  list<AccountRole>  $roles
     * @return list<AccountRole>
     */
    public function missing(string $companyId, array $roles): array;

    /**
     * Maps the role to an account as a new version and audits the change. The account must be an
     * active leaf of the company with a class the role accepts.
     *
     * Fails with a validation error on `account_id` for an invalid target and ApiException
     * VERSION_CONFLICT when the latest version is not `$expectedVersion` (0 for a role never mapped).
     *
     * @param  string|null  $effectiveDate  `YYYY-MM-DD`; today when null
     */
    public function change(string $companyId, AccountRole $role, string $accountId, int $expectedVersion, int $actorId, ?string $effectiveDate = null): AccountRoleMapping;
}
