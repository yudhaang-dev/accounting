<?php

namespace App\Orchestration\Ledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLedgerPolicyRequest extends FormRequest
{
    /**
     * The whole policy is replaced as a new version. `lock_version` is the `version` you read (0 when
     * the company has no policy yet). `soft_closed_transaction_type_keys` are the transaction type
     * keys that may still post into a soft-closed period; empty means none may. `soft_closed_permission`
     * is a permission key the poster must hold as well, or null when the key alone is enough.
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
        ];
    }
}
