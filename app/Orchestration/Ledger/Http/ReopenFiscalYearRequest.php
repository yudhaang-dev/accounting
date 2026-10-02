<?php

namespace App\Orchestration\Ledger\Http;

use Illuminate\Foundation\Http\FormRequest;

class ReopenFiscalYearRequest extends FormRequest
{
    /**
     * `reason` says why the year is reopened; it is kept on the closing and in the audit log.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
