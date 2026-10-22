<?php

namespace App\Modules\Subledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ImportBankStatementRequest extends FormRequest
{
    /**
     * Send the statement as the multipart `file` or as the text `csv`. `closing_balance` is the balance
     * the bank reports on `closing_date`, which is the last date in the file when left out.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['nullable', 'file', 'max:2048', 'required_without:csv'],
            'csv' => ['nullable', 'string', 'max:2097152', 'required_without:file'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'closing_balance' => ['required', 'regex:/^-?\d{1,18}(\.\d{1,6})?$/'],
            'closing_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
