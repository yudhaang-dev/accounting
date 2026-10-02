<?php

namespace App\Orchestration\Ledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ImportHistoryRequest extends FormRequest
{
    /**
     * Imports the history between `ledger_start_date` and the day before `cutover_date` of the opening
     * balance batch, which must exist. `granularity` is `period_summary` (one journal per period,
     * dated within it) or `transactions`. Each journal balances and carries lines like those of the
     * opening balances. `cutover_balances` is the trial balance of the source at the cutover date, one
     * net balance per account in the functional currency; the ledger must carry the same per account
     * once the history is posted, or nothing is imported. Codes are mapped as in the opening balance
     * import; `account_mapping` and `dimension_mapping` only add to what that batch mapped.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_key' => ['required', 'string', 'max:100'],
            'granularity' => ['required', 'in:period_summary,transactions'],
            'account_mapping' => ['sometimes', 'array', 'max:5000'],
            'account_mapping.*.source_code' => ['required', 'string', 'max:64', 'distinct'],
            'account_mapping.*.account_code' => ['required', 'string', 'max:64'],
            'dimension_mapping' => ['sometimes', 'array', 'max:20000'],
            'dimension_mapping.*.type' => ['required', 'string', 'max:64'],
            'dimension_mapping.*.source_code' => ['required', 'string', 'max:64'],
            'dimension_mapping.*.value_id' => ['required', 'string', 'size:26'],
            'journals' => ['required', 'array', 'min:1', 'max:20000'],
            'journals.*.accounting_date' => ['required', 'date_format:Y-m-d'],
            'journals.*.description' => ['nullable', 'string', 'max:500'],
            'journals.*.lines' => ['required', 'array', 'min:2', 'max:5000'],
            'journals.*.lines.*.source_account_code' => ['required', 'string', 'max:64'],
            'journals.*.lines.*.debit' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'journals.*.lines.*.credit' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'journals.*.lines.*.currency' => ['nullable', 'string', 'size:3'],
            'journals.*.lines.*.exchange_rate' => ['nullable', 'regex:/^\d+(\.\d+)?$/'],
            'journals.*.lines.*.dimensions' => ['nullable', 'array'],
            'journals.*.lines.*.dimensions.*' => ['string', 'max:64'],
            'journals.*.lines.*.description' => ['nullable', 'string', 'max:500'],
            'cutover_balances' => ['required', 'array', 'min:1', 'max:5000'],
            'cutover_balances.*.source_account_code' => ['required', 'string', 'max:64', 'distinct'],
            'cutover_balances.*.debit' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'cutover_balances.*.credit' => ['required', 'regex:/^\d+(\.\d+)?$/'],
        ];
    }
}
