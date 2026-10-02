<?php

namespace App\Modules\GeneralLedger\Contracts;

/**
 * The ledger policy of a company, versioned (GL-FR-04, P-04). Whether the keys and the permission
 * exist is for the caller to check: the ledger knows neither transaction types nor roles.
 */
interface LedgerPolicies
{
    public function current(string $companyId): LedgerPolicyData;

    /**
     * Replaces the policy as a new version and audits the change.
     *
     * @param  list<string>  $softClosedTransactionTypeKeys
     * @param  int  $expectedVersion  version the editor saw; 0 when the company has none yet
     *
     * Fails with ApiException VERSION_CONFLICT when the current version is not `$expectedVersion`.
     */
    public function change(string $companyId, array $softClosedTransactionTypeKeys, ?string $softClosedPermission, int $expectedVersion, int $actorId): LedgerPolicyData;
}
