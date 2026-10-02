<?php

namespace App\Modules\TransactionEntry\Contracts;

/**
 * The approval policy of a company, versioned (TE-FR-09, P-04). Whether the transaction type keys and
 * the permissions exist is for the caller to check: this module knows no roles.
 */
interface ApprovalPolicies
{
    public function current(string $companyId): ApprovalPolicyData;

    /**
     * Replaces the policy as a new version and audits the change.
     *
     * @param  list<ApprovalRule>  $rules
     * @param  int  $expectedVersion  version the editor saw; 0 when the company has none yet
     *
     * Fails with ApiException VERSION_CONFLICT when the current version is not `$expectedVersion`.
     */
    public function change(string $companyId, bool $separationOfDuties, array $rules, int $expectedVersion, int $actorId): ApprovalPolicyData;
}
