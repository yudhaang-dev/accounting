<?php

namespace App\Orchestration\Ledger\Http;

use App\Models\User;
use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\LedgerPolicyChange;
use App\Orchestration\Ledger\UpdateLedgerPolicy;

class LedgerPolicyController
{
    /**
     * The current ledger policy: which transaction type keys, and which permission, may still post into
     * a soft-closed period. A company that never set one has version 0, and a soft-closed period then
     * accepts no posting.
     */
    public function show(LedgerPolicies $policies, string $companyId): LedgerPolicyResource
    {
        return new LedgerPolicyResource($policies->current($companyId));
    }

    /**
     * Replaces the policy as a new version; the change is audited. Answers 422 for a transaction type
     * key or permission that does not exist. `lock_version` is required.
     */
    public function update(UpdateLedgerPolicyRequest $request, UpdateLedgerPolicy $update, string $companyId): LedgerPolicyResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lock_version: int, soft_closed_transaction_type_keys: list<string>, soft_closed_permission: string|null, source_override_permission: string|null, fx_rounding_tolerance: string|int|float, revaluation_rate_type_key?: string|null, revaluation_auto_reverse?: bool} $data */
        $data = $request->validated();

        return new LedgerPolicyResource($update(
            $companyId,
            new LedgerPolicyChange(
                $data['soft_closed_transaction_type_keys'],
                $data['soft_closed_permission'],
                $data['source_override_permission'],
                (string) $data['fx_rounding_tolerance'],
                $data['revaluation_rate_type_key'] ?? null,
                $data['revaluation_auto_reverse'] ?? false,
            ),
            (int) $data['lock_version'],
            $user->id,
        ));
    }
}
