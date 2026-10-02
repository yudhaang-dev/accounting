<?php

namespace App\Modules\TransactionEntry\Http;

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
            'fields.*.dimension_type' => ['sometimes', 'string', 'max:64'],
            // Posting rules of a structured version (TE-FR-05); what they refer to is checked by the application layer.
            'posting_rules' => ['sometimes', 'array', 'max:50'],
            'posting_rules.*.type' => ['required', Rule::in([PostingRules::AMOUNT, PostingRules::FX_DIFFERENCE])],
            'posting_rules.*.side' => ['sometimes', 'nullable', Rule::in(['debit', 'credit'])],
            'posting_rules.*.amount_field' => ['sometimes', 'nullable', 'string', 'max:64'],
            'posting_rules.*.account' => ['sometimes', 'nullable', 'array'],
            'posting_rules.*.account.source' => ['required_with:posting_rules.*.account', Rule::in([PostingRules::FIXED_ACCOUNT, PostingRules::ACCOUNT_FIELD])],
            'posting_rules.*.account.account_id' => ['sometimes', 'string', 'size:26'],
            'posting_rules.*.account.field' => ['sometimes', 'string', 'max:64'],
            'posting_rules.*.apply_dimensions' => ['sometimes', 'boolean'],
            'posting_rules.*.reuses_field' => ['sometimes', 'boolean'],
        ];
    }
}
