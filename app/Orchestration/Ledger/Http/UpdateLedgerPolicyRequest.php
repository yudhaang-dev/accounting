<?php

namespace App\Orchestration\Ledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLedgerPolicyRequest extends FormRequest
{
    /**
     * The whole policy is replaced as a new version. `lock_version` is the `version` you read (0 when
     * the company has no policy yet). `soft_closed_transaction_type_keys` are the transaction type
     * keys that may still post into a soft-closed period; empty means none may. `soft_closed_permission`
     * is a permission key the poster must hold as well, or null when the key alone is enough. `source_override_permission` is the permission key that lets a poster post to an account from a source it does not accept, with a reason (GL-FR-07), or null to allow no override. `fx_rounding_tolerance` is the largest rounding difference, in the functional currency, that a foreign currency journal may carry and have booked to the `fx_rounding` account (GL-FR-05); `0` allows none.the  account (GL-FR-05);  allows none.
     * `revaluation_rate_type_key` is the rate type whose closing rate a revaluation uses (GL-FR-09), and
     * `revaluation_auto_reverse` whether it is reversed on the first day of the next period; omitted
     * means no rate type and no reversal.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'soft_closed_transaction_type_keys' => ['present', 'array', 'max:100'],
            'soft_closed_transaction_type_keys.*' => ['string', 'distinct', 'max:64', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'soft_closed_permission' => ['present', 'nullable', 'string', 'max:64'],
            'source_override_permission' => ['present', 'nullable', 'string', 'max:64'],
            'fx_rounding_tolerance' => ['required', 'decimal:0,6', 'min:0'],
            'revaluation_rate_type_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'revaluation_auto_reverse' => ['sometimes', 'boolean'],
        ];
    }
}
