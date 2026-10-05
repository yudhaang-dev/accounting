<?php

namespace App\Orchestration\Ledger\Http;

use App\Modules\Subledger\Contracts\ControlRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportOpeningBalancesRequest extends FormRequest
{
    /**
     * `source_key` identifies the batch in the source system: sending it again with the same content
     * returns the batch that exists, other content is refused. `cutover_date` is the first day the
     * company is booked in the system; `ledger_start_date` is the date of the balances, the first day
     * of a period, and defaults to `cutover_date`. `account_mapping` translates every source account
     * code of the lines to an account code of the company, and `dimension_mapping` every source value
     * code of a dimension type to a dimension value ID; codes without a mapping refuse the batch.
     * Each line carries exactly one of `debit` and `credit`; a foreign currency line carries its
     * `currency` and the `exchange_rate` of the source. When the ledger starts inside a fiscal year,
     * income and expense lines carry the year to date.
     *
     * `open_items` are the open items of the source system at the ledger start date (SL-FR-10): each names its
     * party by `party_id`, or by `party_ref` under the `source_system` of the batch, the `control_role` and the
     * `kind` (`invoice` raises the balance of the control account, `credit_note` lowers it), the document number
     * and date, what is left to settle in `amount` and, for a foreign `currency`, the `exchange_rate`. Their total
     * per control account must equal the lines of the batch on that account, or the whole batch is refused.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_key' => ['required', 'string', 'max:100'],
            'source_system' => ['nullable', 'string', 'max:64'],
            'cutover_date' => ['required', 'date_format:Y-m-d'],
            'ledger_start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:cutover_date'],
            'account_mapping' => ['required', 'array', 'min:1', 'max:5000'],
            'account_mapping.*.source_code' => ['required', 'string', 'max:64', 'distinct'],
            'account_mapping.*.account_code' => ['required', 'string', 'max:64'],
            'dimension_mapping' => ['sometimes', 'array', 'max:20000'],
            'dimension_mapping.*.type' => ['required', 'string', 'max:64'],
            'dimension_mapping.*.source_code' => ['required', 'string', 'max:64'],
            'dimension_mapping.*.value_id' => ['required', 'string', 'size:26'],
            'lines' => ['required', 'array', 'min:2', 'max:20000'],
            'lines.*.source_account_code' => ['required', 'string', 'max:64'],
            'lines.*.debit' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'lines.*.credit' => ['required', 'regex:/^\d+(\.\d+)?$/'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
            'lines.*.exchange_rate' => ['nullable', 'regex:/^\d+(\.\d+)?$/'],
            'lines.*.dimensions' => ['nullable', 'array'],
            'lines.*.dimensions.*' => ['string', 'max:64'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'open_items' => ['sometimes', 'array', 'max:50000'],
            'open_items.*.party_id' => ['nullable', 'string', 'size:26', 'required_without:open_items.*.party_ref'],
            'open_items.*.party_ref' => ['nullable', 'string', 'max:128', 'required_without:open_items.*.party_id'],
            'open_items.*.control_role' => ['required', Rule::enum(ControlRole::class)],
            'open_items.*.kind' => ['required', Rule::in(['invoice', 'credit_note'])],
            'open_items.*.document_number' => ['required', 'string', 'max:100'],
            'open_items.*.document_date' => ['required', 'date_format:Y-m-d'],
            'open_items.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'open_items.*.currency' => ['nullable', 'string', 'size:3'],
            'open_items.*.amount' => ['required', 'regex:/^\d+(\.\d{1,6})?$/'],
            'open_items.*.exchange_rate' => ['nullable', 'regex:/^\d+(\.\d+)?$/'],
        ];
    }
}
