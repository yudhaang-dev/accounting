<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Content of a draft entry. Only the shape is checked here; accounts, period and balance are
 * validated when the entry is submitted (TE-FR-08). Amounts are decimal strings (API-TR-05).
 */
class EntryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function contentRules(): array
    {
        return [
            'accounting_date' => ['required', 'date_format:Y-m-d'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:500'],
            // A free-lines entry carries `lines`, a structured one `values`; the version decides which is required.
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*' => ['array'],
            'lines.*.account_id' => ['required', 'string', 'size:26'],
            'lines.*.debit' => ['nullable', 'string', 'max:40'],
            'lines.*.credit' => ['nullable', 'string', 'max:40'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            // Dimension type key => dimension value ID; resolved to a dimension set on submit and post (MD-FR-04).
            /** @var array<string, string|null> */
            'lines.*.dimensions' => ['nullable', 'array'],
            'lines.*.dimensions.*' => ['nullable', 'string', 'size:26'],
            // Date the system reverses the entry on once it is posted, and the posted entry it replaces (TE-FR-11).
            'auto_reverse_on' => ['nullable', 'date_format:Y-m-d'],
            'replaces_entry_id' => ['nullable', 'string', 'size:26'],
            // The currency and the rate a structured entry brings; a free-lines entry has them on each line (TE-FR-06).
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'string', 'max:40'],
            'lines.*.currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'string', 'max:40'],
            // Field name => value, by the field schema of the version (TE-FR-04).
            'values' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transaction_type_key' => ['required', 'string', 'max:64'],
            'transaction_type_version' => ['nullable', 'integer', 'min:1'],
            ...$this->contentRules(),
        ];
    }
}
