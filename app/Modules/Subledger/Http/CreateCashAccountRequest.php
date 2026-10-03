<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\CashAccountKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCashAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(CashAccountKind::class)],
            // A bank account names its bank and number; a cash box has neither.
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
            // The only currency of the account; it must be activated for the company.
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            // The asset leaf account of the ledger that holds the balance; it accepts the subledger source only.
            'account_id' => ['required', 'string', 'size:26'],
        ];
    }
}
