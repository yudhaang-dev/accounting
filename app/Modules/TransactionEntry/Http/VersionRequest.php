<?php

namespace App\Modules\TransactionEntry\Http;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\TransactionEntry\Domain\FieldType;
use App\Modules\TransactionEntry\Domain\PostingRules;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Content of a draft version: the mode and, for `structured`, its field definitions (TE-FR-04).
 * Whether referenced accounts and dimension types exist is checked by the application layer.
 */
class VersionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(TransactionTypeMode::class)],
            'fields' => ['present', 'array', 'max:50'],
            'fields.*.name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.type' => ['required', Rule::enum(FieldType::class)],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.account_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'fields.*.account_ids.*' => ['string', 'size:26'],
            // The classes of account an account field accepts (TE-FR-04); an empty list is not sent, it means every class.
            'fields.*.account_classes' => ['sometimes', 'array', 'min:1', 'max:5'],
            'fields.*.account_classes.*' => ['string', Rule::enum(AccountClass::class)],
            'fields.*.dimension_type' => ['sometimes', 'string', 'max:64'],
            // The roles a party field accepts (TE-FR-04); that they exist and are active is checked by the application layer.
            'fields.*.allowed_roles' => ['sometimes', 'array', 'min:1', 'max:50'],
            'fields.*.allowed_roles.*' => ['string', 'max:32'],
            // Currencies and rates of entries (TE-FR-06); whether the currencies are active is checked by the application layer.
            'allowed_currencies' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allowed_currencies.*' => ['string', 'regex:/^[A-Z]{3}$/'],
            'rate_type_key' => ['sometimes', 'nullable', 'string', 'max:32'],
            'allow_rate_input' => ['sometimes', 'boolean'],
            // Posting rules of a structured version (TE-FR-05); what they refer to is checked by the application layer.
            'posting_rules' => ['sometimes', 'array', 'max:50'],
            'posting_rules.*.type' => ['required', Rule::in([PostingRules::AMOUNT, PostingRules::FX_DIFFERENCE])],
            'posting_rules.*.side' => ['sometimes', 'nullable', Rule::in(['debit', 'credit'])],
            'posting_rules.*.amount_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'posting_rules.*.account' => ['sometimes', 'nullable', 'array'],
            'posting_rules.*.account.source' => ['required_with:posting_rules.*.account', Rule::in([PostingRules::FIXED_ACCOUNT, PostingRules::ACCOUNT_FIELD, PostingRules::CONTROL_ACCOUNT, PostingRules::CASH_ACCOUNT])],
            'posting_rules.*.account.account_id' => ['sometimes', 'string', 'size:26'],
            'posting_rules.*.account.field' => ['sometimes', 'string', 'max:64'],
            'posting_rules.*.account.party_field' => ['sometimes', 'string', 'max:64'],
            'posting_rules.*.apply_dimensions' => ['sometimes', 'boolean'],
            'posting_rules.*.reuses_field' => ['sometimes', 'boolean'],
            // The party field whose group company the line carries as its counterparty (GL-FR-15).
            'posting_rules.*.counterparty_party_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The document an entry of the version records in the subledger (TE-FR-10); how it fits the fields and rules is checked by the application layer.
            'subledger_route' => ['sometimes', 'nullable', 'array'],
            'subledger_route.kind' => ['required_with:subledger_route', 'string', 'max:32'],
            'subledger_route.control_role' => ['sometimes', 'nullable', 'string', 'max:32'],
            'subledger_route.party_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subledger_route.document_number_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subledger_route.due_date_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subledger_route.cash_account_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subledger_route.counterpart_cash_account_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subledger_route.allocations_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subledger_route.posting_profile_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
