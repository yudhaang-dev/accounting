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
    /** Everything the payload may carry; anything else is refused instead of ignored (API-AC-06). */
    private const SECTIONS = ['transaction_type', 'accounting_date', 'currency', 'exchange_rate', 'amounts', 'dimensions', 'party', 'cash_account', 'allocations', 'references'];

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
     * `transaction_type` is the key and, optionally, the version (the latest published one by default).
     * `amounts` and `dimensions` carry the values the source system computed, by field name and by
     * dimension type key; `references.source_transaction_id` identifies the event in the source system.
     * `source_system` is refused: it comes from the client, not from the body.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transaction_type' => ['required', 'array'],
            'transaction_type.key' => ['required', 'string', 'max:64'],
            'transaction_type.version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'accounting_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['sometimes', 'nullable', 'string', 'max:40'],
            'amounts' => ['present', 'array'],
            'dimensions' => ['sometimes', 'array'],
            'dimensions.*' => ['nullable', 'string', 'size:26'],
            'party' => ['sometimes', 'nullable', 'string', 'size:26'],
            'cash_account' => ['sometimes', 'nullable', 'string', 'size:26'],
            'allocations' => ['sometimes', 'nullable', 'array'],
            'references' => ['required', 'array'],
            'references.source_transaction_id' => ['required', 'string', 'max:255'],
            'references.source_document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_system' => ['prohibited'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->input()), [...self::SECTIONS, 'source_system']) as $key) {
                    $validator->errors()->add((string) $key, __('entries.event_section_unknown', ['section' => $key]));
                }
            },
        ];
    }
}
