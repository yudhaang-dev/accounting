<?php

namespace App\Orchestration\Approval\Http;

use App\Models\User;
use App\Modules\TransactionEntry\Contracts\ApprovalPolicies;
use App\Modules\TransactionEntry\Contracts\ApprovalRule;
use App\Orchestration\Approval\UpdateApprovalPolicy;

class ApprovalPolicyController
{
    /**
     * The current approval policy (TE-FR-09). A company that never set one has version 0 and no rules:
     * every entry then needs one approval by a holder of `entries.approve`.
     */
    public function show(ApprovalPolicies $policies, string $companyId): ApprovalPolicyResource
    {
        return new ApprovalPolicyResource($policies->current($companyId));
    }

    /**
     * Replaces the policy as a new version; the change is audited. Answers 422 for a transaction type
     * key or permission that does not exist. `lock_version` is required.
     */
    public function update(UpdateApprovalPolicyRequest $request, UpdateApprovalPolicy $update, string $companyId): ApprovalPolicyResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lock_version: int, separation_of_duties: bool, rules: list<array{transaction_type_key: string|null, source: string|null, threshold: string|int|float|null, stages: list<string>, auto_post: bool}>} $data */
        $data = $request->validated();

        $rules = array_map(fn (array $rule): ApprovalRule => new ApprovalRule(
            $rule['transaction_type_key'],
            $rule['source'],
            $rule['threshold'] === null ? null : (string) $rule['threshold'],
            $rule['stages'],
            $rule['auto_post'],
        ), $data['rules']);

        return new ApprovalPolicyResource($update($companyId, $data['separation_of_duties'], $rules, (int) $data['lock_version'], $user->id));
    }
}
