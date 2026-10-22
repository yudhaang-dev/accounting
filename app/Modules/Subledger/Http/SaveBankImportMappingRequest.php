<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\BankImportMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBankImportMappingRequest extends FormRequest
{
    /**
     * Columns are positions counted from 1. Give `amount_column` (signed, positive is money in) or both
     * `money_in_column` and `money_out_column`. `lock_version` is required once a mapping exists.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'delimiter' => ['required', Rule::in([',', ';', "\t", '|'])],
            'date_format' => ['required', Rule::in(BankImportMapping::DATE_FORMATS)],
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'has_header' => ['required', 'boolean'],
            'date_column' => ['required', 'integer', 'min:1', 'max:50'],
            'description_column' => ['nullable', 'integer', 'min:1', 'max:50'],
            'reference_column' => ['nullable', 'integer', 'min:1', 'max:50'],
            'amount_column' => ['nullable', 'integer', 'min:1', 'max:50'],
            'money_in_column' => ['nullable', 'integer', 'min:1', 'max:50'],
            'money_out_column' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
