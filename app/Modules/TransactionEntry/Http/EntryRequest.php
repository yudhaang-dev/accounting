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
            'lines' => ['required', 'array', 'max:500'],
            'lines.*' => ['array'],
            'lines.*.account_id' => ['required', 'string', 'size:26'],
            'lines.*.debit' => ['nullable', 'string', 'max:40'],
            'lines.*.credit' => ['nullable', 'string', 'max:40'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
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
