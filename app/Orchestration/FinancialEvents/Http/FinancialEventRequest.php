<?php

namespace App\Orchestration\FinancialEvents\Http;

use App\Modules\IdentityAccess\Contracts\AccessContexts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The canonical payload of a financial event (API-TR-17, API-TR-18). Only a client with the transaction
 * type on its allowlist may send it; the check comes before validation so that nothing is said about a
 * type the client may not use.
 */
class FinancialEventRequest extends FormRequest
{
    public function authorize(AccessContexts $contexts): bool
    {
        $principal = $contexts->integrationPrincipal($this);

        if ($principal === null) {
            return false;
        }

        $key = data_get($this->input(), 'transaction_type.key');

        return ! is_string($key) || $principal->allowsTransactionType($key);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return FinancialEventPayload::rules();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => FinancialEventPayload::refuseUnknownSections($validator, $this->input()),
        ];
    }
}
