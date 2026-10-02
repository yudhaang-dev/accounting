<?php

namespace App\Modules\TransactionEntry\Http;

use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalRequest extends FormRequest
{
    /**
     * `reversal_date` is the accounting date of the reversal; its period must accept the posting.
     * `reason` is kept on the entry (TE-FR-11).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
